<?php

namespace App\Helpers;

use DTApi\Models\UserMeta;
use DTApi\Models\UserLanguages;
use DTApi\Models\Job;
use DTApi\Helpers\TeHelper;
use DTApi\Helpers\DateTimeHelper;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;


class GlobalHelper
{
    /**
     * Convert number of minutes to hour and minute variant
     * @param  int $time   Number of minutes to convert
     * @param  string $format Format for the output string
     * @return string         Formatted time string
     */
    public static function convertToHoursMins($time, $format = '%02dh %02dmin')
    {
        if ($time < 60) {
            return $time . 'min';
        } else if ($time == 60) {
            return '1h';
        }

        $hours = floor($time / 60);
        $minutes = ($time % 60);
        
        return sprintf($format, $hours, $minutes);
    }
	
	/**
     * Function to get all potential jobs of a user with their ID
     * @param int $user_id User ID
     * @return array       List of potential jobs
     */
    public static function getPotentialJobIdsWithUserId($user_id)
    {
        $user_meta = UserMeta::where('user_id', $user_id)->first();
        if (!$user_meta) {
            return [];
        }

        $translator_type = $user_meta->translator_type;
        $job_type = match ($translator_type) {
            'professional' => 'paid',       // Show all jobs for professionals
            'rwstranslator' => 'rws',       // Show RWS jobs for RWS translators
            default => 'unpaid',           // Show unpaid jobs for volunteers
        };

        $languages = UserLanguages::where('user_id', $user_id)->pluck('lang_id')->all();
        $gender = $user_meta->gender;
        $translator_level = $user_meta->translator_level;
        
        $job_ids = Job::getJobs($user_id, $job_type, 'pending', $languages, $gender, $translator_level);

        $filtered_job_ids = array_filter($job_ids, function ($job) use ($user_id) {
            $job = Job::find($job->id);
            if (!$job) {
                return false;
            }
            
            $checktown = Job::checkTowns($job->user_id, $user_id);
            return !(
                ($job->customer_phone_type === 'no' || $job->customer_phone_type === '') &&
                $job->customer_physical_type === 'yes' &&
                !$checktown
            );
        });

        return TeHelper::convertJobIdsInObjs($filtered_job_ids);
    }
	
	/**
     * Function to send OneSignal push notifications with user tags
     * @param array $users        List of user IDs to send the notification to
     * @param int $job_id         Job ID associated with the notification
     * @param array $data         Additional data for the notification
     * @param array $msg_text     Message text for the notification
     * @param bool $is_need_delay Whether to delay the notification
     * @return void
     */
    public static function sendPushNotificationToSpecificUsers($users, $job_id, $data, $msg_text, $is_need_delay)
    {
        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());

        $logger->info('Push send for job ' . $job_id, [$users, $data, $msg_text, $is_need_delay]);

        $environment = env('APP_ENV');
        $onesignalAppID = config("app." . ($environment === 'prod' ? 'prodOnesignalAppID' : 'devOnesignalAppID'));
        $onesignalRestAuthKey = sprintf("Authorization: Basic %s", config("app." . ($environment === 'prod' ? 'prodOnesignalApiKey' : 'devOnesignalApiKey')));

        $user_tags = $this->getUserTagsStringFromArray($users);
        $data['job_id'] = $job_id;

        $ios_sound = 'default';
        $android_sound = 'default';

        if (isset($data['notification_type']) && $data['notification_type'] === 'suitable_job') {
            if (isset($data['immediate']) && $data['immediate'] === 'no') {
                $android_sound = 'normal_booking';
                $ios_sound = 'normal_booking.mp3';
            } else {
                $android_sound = 'emergency_booking';
                $ios_sound = 'emergency_booking.mp3';
            }
        }

        $fields = [
            'app_id'         => $onesignalAppID,
            'tags'           => json_decode($user_tags, true),
            'data'           => $data,
            'title'          => ['en' => 'DigitalTolk'],
            'contents'       => $msg_text,
            'ios_badgeType'  => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound'  => $android_sound,
            'ios_sound'      => $ios_sound
        ];

        if ($is_need_delay) {
            $fields['send_after'] = DateTimeHelper::getNextBusinessTimeString();
        }

        $fields = json_encode($fields);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', $onesignalRestAuthKey]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $logger->error('Curl error: ' . curl_error($ch));
        } else {
            $logger->info('Push send for job ' . $job_id . ' curl answer', [$response]);
        }

        curl_close($ch);
    }

    /**
     * making user_tags string from users array for creating onesignal notifications
     * @param $users
     * @return string
     */
    public static function getUserTagsStringFromArray($users)
    {
        $user_tags = "[";
        $first = true;
        foreach ($users as $oneUser) {
            if ($first) {
                $first = false;
            } else {
                $user_tags .= ',{"operator": "OR"},';
            }
            $user_tags .= '{"key": "email", "relation": "=", "value": "' . strtolower($oneUser->email) . '"}';
        }
        $user_tags .= ']';
        return $user_tags;
    }
	
	/**
     * Alerts function to retrieve job alerts based on specific filters
     * @return array Filtered jobs and associated data
     */
    public static function alerts()
    {
        $jobs = Job::all();
        $sesJobs = [];
        $jobId = [];
        
        foreach ($jobs as $job) {
            $sessionTime = explode(':', $job->session_time);
            if (count($sessionTime) >= 3) {
                $totalMinutes = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);

                if ($totalMinutes >= $job->duration && $totalMinutes >= $job->duration * 2) {
                    $sesJobs[] = $job;
                }
            }
        }

        $jobId = array_column($sesJobs, 'id');

        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestdata = Request::all();
        $all_customers = DB::table('users')->where('user_type', '1')->pluck('email');
        $all_translators = DB::table('users')->where('user_type', '2')->pluck('email');

        $cuser = Auth::user();

        $allJobsQuery = DB::table('jobs')
            ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
            ->whereIn('jobs.id', $jobId)
            ->where('jobs.ignore', 0)
            ->select('jobs.*', 'languages.language');

        if (isset($requestdata['lang']) && $requestdata['lang'] !== '') {
            $allJobsQuery->whereIn('jobs.from_language_id', $requestdata['lang']);
        }

        if (isset($requestdata['status']) && $requestdata['status'] !== '') {
            $allJobsQuery->whereIn('jobs.status', $requestdata['status']);
        }

        if (isset($requestdata['customer_email']) && $requestdata['customer_email'] !== '') {
            $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
            if ($user) {
                $allJobsQuery->where('jobs.user_id', '=', $user->id);
            }
        }

        if (isset($requestdata['translator_email']) && $requestdata['translator_email'] !== '') {
            $user = DB::table('users')->where('email', $requestdata['translator_email'])->first();
            if ($user) {
                $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->pluck('job_id');
                $allJobsQuery->whereIn('jobs.id', $allJobIDs);
            }
        }

        if (isset($requestdata['filter_timetype'])) {
            if ($requestdata['filter_timetype'] === "created") {
                if (isset($requestdata['from']) && $requestdata['from'] !== "") {
                    $allJobsQuery->where('jobs.created_at', '>=', $requestdata["from"]);
                }
                if (isset($requestdata['to']) && $requestdata['to'] !== "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobsQuery->where('jobs.created_at', '<=', $to);
                }
                $allJobsQuery->orderBy('jobs.created_at', 'desc');
            }

            if ($requestdata['filter_timetype'] === "due") {
                if (isset($requestdata['from']) && $requestdata['from'] !== "") {
                    $allJobsQuery->where('jobs.due', '>=', $requestdata["from"]);
                }
                if (isset($requestdata['to']) && $requestdata['to'] !== "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobsQuery->where('jobs.due', '<=', $to);
                }
                $allJobsQuery->orderBy('jobs.due', 'desc');
            }
        }

        if (isset($requestdata['job_type']) && $requestdata['job_type'] !== '') {
            $allJobsQuery->whereIn('jobs.job_type', $requestdata['job_type']);
        }

        $allJobs = $allJobsQuery->paginate(15);

        return [
            'allJobs' => $allJobs,
            'languages' => $languages,
            'all_customers' => $all_customers,
            'all_translators' => $all_translators,
            'requestdata' => $requestdata
        ];
    }
	
	/**
     * Send notification to translators for a specific job
     * @param Job $job
     * @param array $data
     * @param int $exclude_user_id
     */
    public static function sendNotificationTranslator($job, $data = [], $exclude_user_id)
    {
        $users = User::where('user_type', '2')->where('status', '1')->where('id', '!=', $exclude_user_id)->get();
        $translatorArray = [];            // Suitable translators (no need to delay push)
        $delayedTranslatorArray = [];     // Suitable translators (need to delay push)

        foreach ($users as $user) {
            if (!GlobalHelper::isNeedToSendPush($user->id)) {
                continue;
            }

            $notGetEmergency = TeHelper::getUsermeta($user->id, 'not_get_emergency');
            if ($data['immediate'] === 'yes' && $notGetEmergency === 'yes') {
                continue;
            }

            $potentialJobs = GlobalHelper::getPotentialJobIdsWithUserId($user->id);
            foreach ($potentialJobs as $potentialJob) {
                if ($job->id === $potentialJob->id) {
                    $jobForTranslator = Job::assignedToPaticularTranslator($user->id, $potentialJob->id);
                    if ($jobForTranslator === 'SpecificJob') {
                        $jobChecker = Job::checkParticularJob($user->id, $potentialJob);
                        if ($jobChecker !== 'userCanNotAcceptJob') {
                            if (GlobalHelper::isNeedToDelayPush($user->id)) {
                                $delayedTranslatorArray[] = $user;
                            } else {
                                $translatorArray[] = $user;
                            }
                        }
                    }
                }
            }
        }

        $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = 'suitable_job';
        $messageContent = $data['immediate'] === 'no'
            ? 'Ny bokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min ' . $data['due']
            : 'Ny akutbokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min';

        $messageText = ["en" => $messageContent];

        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job->id, [$translatorArray, $delayedTranslatorArray, $messageText, $data]);

        GlobalHelper::sendPushNotificationToSpecificUsers($translatorArray, $job->id, $data, $messageText, false);
        GlobalHelper::sendPushNotificationToSpecificUsers($delayedTranslatorArray, $job->id, $data, $messageText, true);
    }
	

	/**
     * Convert job details to data array for notification purposes
     * @param Job $job
     * @return array
     */
    public static function jobToData($job)
    {
        $data = [
            'job_id' => $job->id,
            'from_language_id' => $job->from_language_id,
            'immediate' => $job->immediate,
            'duration' => $job->duration,
            'status' => $job->status,
            'gender' => $job->gender,
            'certified' => $job->certified,
            'due' => $job->due,
            'job_type' => $job->job_type,
            'customer_phone_type' => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town' => $job->town,
            'customer_type' => $job->user->userMeta->customer_type,
        ];

        $dueDateTime = explode(' ', $job->due);
        $data['due_date'] = $dueDateTime[0];
        $data['due_time'] = $dueDateTime[1];

        $data['job_for'] = [];

        if ($job->gender) {
            $data['job_for'][] = $job->gender === 'male' ? 'Man' : 'Kvinna';
        }

        if ($job->certified) {
            switch ($job->certified) {
                case 'both':
                    $data['job_for'][] = 'Godkänd tolk';
                    $data['job_for'][] = 'Auktoriserad';
                    break;
                case 'yes':
                    $data['job_for'][] = 'Auktoriserad';
                    break;
                case 'n_health':
                    $data['job_for'][] = 'Sjukvårdstolk';
                    break;
                case 'law':
                case 'n_law':
                    $data['job_for'][] = 'Rätttstolk';
                    break;
                default:
                    $data['job_for'][] = $job->certified;
                    break;
            }
        }

        return $data;
    }
	
	/**
     * Function to delay the push
     * @param int $user_id
     * @return bool
     */
    public static function isNeedToDelayPush($user_id)
    {
        if (!DateTimeHelper::isNightTime()) {
            return false;
        }

        $notGetNighttime = TeHelper::getUsermeta($user_id, 'not_get_nighttime');
        return $notGetNighttime === 'yes';
    }

    /**
     * Function to check if a push notification needs to be sent
     * @param int $user_id
     * @return bool
     */
    public static function isNeedToSendPush($user_id)
    {
        $notGetNotification = TeHelper::getUsermeta($user_id, 'not_get_notification');
        return $notGetNotification !== 'yes';
    }
	
	    /*
     * TODO remove method and add service for notification
     * TEMP method
     * send session start remind notification
     */
	 
    public static function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
    {

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/cron/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
        $data = array();
        $data['notification_type'] = 'session_start_remind';
        $due_explode = explode(' ', $due);
        if ($job->customer_physical_type == 'yes')
            $msg_text = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (på plats i ' . $job->town . ') kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );
        else
            $msg_text = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (telefon) kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min.Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            GlobalHelper::sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
            $this->logger->addInfo('sendSessionStartRemindNotification ', ['job' => $job->id]);
        }
    }
	
	/**
     * Function to send the notification for sending the admin job cancel
     * @param $job_id
     */
    public function sendNotificationByAdminCancelJob($job_id)
    {
        $job = Job::findOrFail($job_id);
        $user_meta = $job->user->userMeta()->first();
        $data = array();            // save job's information to data for sending Push
        $data['job_id'] = $job->id;
        $data['from_language_id'] = $job->from_language_id;
        $data['immediate'] = $job->immediate;
        $data['duration'] = $job->duration;
        $data['status'] = $job->status;
        $data['gender'] = $job->gender;
        $data['certified'] = $job->certified;
        $data['due'] = $job->due;
        $data['job_type'] = $job->job_type;
        $data['customer_phone_type'] = $job->customer_phone_type;
        $data['customer_physical_type'] = $job->customer_physical_type;
        $data['customer_town'] = $user_meta->city;
        $data['customer_type'] = $user_meta->customer_type;

        $due_Date = explode(" ", $job->due);
        $due_date = $due_Date[0];
        $due_time = $due_Date[1];
        $data['due_date'] = $due_date;
        $data['due_time'] = $due_time;
        $data['job_for'] = array();
        if ($job->gender != null) {
            if ($job->gender == 'male') {
                $data['job_for'][] = 'Man';
            } else if ($job->gender == 'female') {
                $data['job_for'][] = 'Kvinna';
            }
        }
        if ($job->certified != null) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'normal';
                $data['job_for'][] = 'certified';
            } else if ($job->certified == 'yes') {
                $data['job_for'][] = 'certified';
            } else {
                $data['job_for'][] = $job->certified;
            }
        }
        GlobalHelper::sendNotificationTranslator($job, $data, '*');   // send Push all sutiable translators
    }

	
}
