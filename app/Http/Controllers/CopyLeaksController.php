<?php

namespace App\Http\Controllers;

use App\DTOs\CopyLeaksAuthResponse;
use App\Models\Export;
use App\Models\Files;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class CopyLeaksController extends Controller
{
    /**
     * @throws Throwable
     */
    public function uploadFile(Request $request) {
        DB::beginTransaction();
        try {
            $request->validate([
                'title' => 'required'
            ]);

            $fileName = Storage::disk('local')->put('Uploads', $request->file('document'));

            $file = new Files;
            $file->user_id = Auth::user()->id;
            $file->title = $request->input('title');
            $file->path = $fileName;
            $file->save();

            DB::commit();

            return back()->with('success');
        } catch (Exception $exception) {
            DB::rollBack();
            dd($exception);
        }
    }

    public function downloadFile($id): StreamedResponse
    {
        $file = Files::find($id);
        return Storage::download($file->path);
    }

    /**
     * @throws Throwable
     */
    public function requestForExport ($id) {
        DB::beginTransaction();
        try {
            $file = Files::find($id);
            $file->requested_for_export = true;
            $file->save();

            $authResponse = Http::post("https://id.copyleaks.com/v3/account/login/api", [
                "Key" => env('COPYLEAKS_KEY'),
                "Email" => env('COPYLEAKS_EMAIL')
            ]);

            if ($authResponse->status() == 200) {
                $copyLeaksAuthResponse = new CopyLeaksAuthResponse($authResponse->json());

                $export = new Export;
                $export->file_id = $file->id;
                $export->save();

                $data = [
                    "completionWebhook" => env("NGROK_URL") . "/api/exported",
                    "pdfReport" => [
                        "verb" => "POST",
                        "endpoint" => env("NGROK_URL") . "/api/pdf-report/". $file->id,
                    ],
                    "crawledVersion" => [
                        "verb" => "POST",
                        "endpoint" => env("NGROK_URL") . "/api/crawled-report/" . $file->id,
                    ],
                ];

                Log::info(json_encode($data));

                $response = Http::withHeaders([
                    "Content-Type"=> "application/json",
                    "Authorization" => "Bearer " . $copyLeaksAuthResponse->getAccessToken(),
                ])->post("https://api.copyleaks.com/v3/downloads/scan_" . $file->id . "/export/exported_" . $export->id, $data);

                if ($response->status() == 204) {
                    DB::commit();
                    return back()->with('success', ["message" => "Requested for results successfully"]);
                } else {
                    dd($response);
                    throw new Exception("Failed to request for results");
                }
            } else {
                throw new Exception("Failed to authenticate with CopyLeaks");
            }

        } catch (Exception $exception) {
            DB::rollBack();
            dd($exception);
        }
    }

    public function showResults ($id) {
        $file = Files::find($id);
        return view('student.results', ["file" => $file]);
    }
}
