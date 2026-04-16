<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

class ProcessLaptopAanmelden implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
        Log::info('LaptopAanmelden: job created.');

    }

    /**
     * Read data from Nextcloud Forms.
     * $filePath determines what data to read:
     *   $filePath: "" => forms lezen
     *   $filePath: "/1/questions" => questions lezen
     *   $filePath: "/1/submissions" => submissions lezen
     * $filePath: "/1/submissions/1" => submission 1 lezen
     */
    private function readNextcloudForms($filePath)
    {
        $url = env('LR_NEXTCLOUD_URL') . '/ocs/v2.php/apps/forms/api/v3/forms' . $filePath;
        $username = env('LR_NEXTCLOUD_USERNAME');
        $password = env('LR_NEXTCLOUD_PASSWORD');

        $response = Http::withHeaders([
            'OCS-APIREQUEST' => 'true',
            'Accept' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode($username . ':' . $password)
        ])->get($url);

        $ocs_data = [];
        if ($response->successful()) {
            $ocs_response = $response->body();
            $ocs = json_decode($ocs_response, true);
            $ocs_status = $ocs['ocs']['meta']['status'] ?? 'unknown';
            Log::info('Nextcloud response status: ' . $ocs_status);
            if ($ocs_status === 'ok') {
                $ocs_data = $ocs['ocs']['data'] ?? [];
            }
            Log::info('Nextcloud OCS API response status: ' . $ocs_status);
        } else {
            Log::error('Nextcloud Failed to read: ' . $response->status());
            return $ocs_data;
        }
        return $ocs_data;
    }

    /**
     * Read forms, questions and submissions from Nextcloud Forms.
     */
    private function readNextCloud()
    {
        //
        $results = [];
        $forms = $this->readNextcloudForms('');
        Log::info('LaptopAanmelden: forms gelezen: ' . count($forms));
        foreach ($forms as $form) {
            Log::info('LaptopAanmelden: form: ' . $form['id'] . ' -' . $form['title'].'-');
            if ($form['title'] === 'Laptop aanmelden') {
                Log::info('LaptopAanmelden: form gevonden: ' . $form['id'] . ' - ' . $form['title']);
                $questions = $this->readNextcloudForms('/' . $form['id'] . '/questions');
                Log::info('LaptopAanmelden: questions gelezen: ' . count($questions));
                foreach ($questions as $question) {
                    Log::info('LaptopAanmelden: question: ' . $question['id'] . ' - ' . $question['text']);
                }

                $submissions_data = $this->readNextcloudForms('/' . $form['id'] . '/submissions');
                $submissions = $submissions_data['submissions'] ?? [];
                Log::info('LaptopAanmelden: submissions gelezen: ' . count($submissions));
                foreach ($submissions as $submission) {
                    Log::info('LaptopAanmelden: submission: ' . $submission['id'] . ' - ' . $submission['userDisplayName']);
                    $submissionDetails = $this->readNextcloudForms('/' . $form['id'] . '/submissions/' . $submission['id']);
                    //Log::info('LaptopAanmelden: submission details: ' . json_encode($submissionDetails));
                    $result = [];
                    $result["submissionid"] = $submission['id'];
                    $answers = $submissionDetails['answers'] ?? [];
                    foreach ($answers as $answer) {
                        Log::info('LaptopAanmelden: answer: ' . $answer['questionId'] . ' - ' . $answer['text']);
                        foreach ($questions as $question) {
                            if ($question['id'] === $answer['questionId']) {
                                $key = str_replace(' ', '', strtolower($question['text']));
                                $result[$key] = $answer['text'];    
                            }
                            // Log::info('LaptopAanmelden: question: ' . $question['id'] . ' - ' . $question['text']);
                        }
                    }
                    $results[] = $result;
                }
                // TODO: delete all submissions after processing, to avoid processing the same submissions again in the next run. This can be done by sending a DELETE request to the Nextcloud Forms API for each submission, e.g. DELETE /ocs/v2.php/apps/forms/api/v3/forms/{formId}/submissions/{submissionId}
            }


        }
        return $results;
    }

    public function handle(): void {

        //
        Log::info('LaptopAanmelden: job gestart '.date("Y-m-d H:i:s").'.');
        $laptops =$this->readNextCloud();
        Log::info('LaptopAanmelden: $laptops: ' . count($laptops));
        foreach ($laptops as $laptop) {
            Log::info('LaptopAanmelden: laptop: ' . print_r($laptop, true) );
        }   
        Log::info('LaptopAanmelden: job finished '.date("Y-m-d H:i:s").'.');
    }
}
