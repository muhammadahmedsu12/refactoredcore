<?php

namespace DTApi\Http\Controllers;

use DTApi\Models\Job;
use DTApi\Http\Requests;
use DTApi\Models\Distance;
use Illuminate\Http\Request;
use DTApi\Repository\BookingRepository;
use App\Constants\GlobalConstants; // Added for constants

/**
 * Class BookingController
 * @package DTApi\Http\Controllers
 */
class BookingController extends Controller
{
    /**
     * @var BookingRepository
     */
    protected $repository;

    /**
     * BookingController constructor.
     * @param BookingRepository $bookingRepository
     */
    public function __construct(BookingRepository $bookingRepository)
    {
        $this->repository = $bookingRepository;
    }

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request)
    {
        $response = null;

        if ($user_id = $request->get('user_id')) {
            $response = $this->repository->getUsersJobs($user_id);
        } elseif (in_array($request->__authenticatedUser->user_type, [GlobalConstants::ADMIN_ROLE_ID, GlobalConstants::SUPERADMIN_ROLE_ID])) {
            $response = $this->repository->getAll($request);
        }

        return response($response);
    }

    /**
     * Show the specified resource.
     *
     * @param int $id
     * @return mixed
     */
    public function show($id)
    {
        $job = $this->repository->with('translatorJobRel.user')->find($id);

        return response($job);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param Request $request
     * @return mixed
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            // Add necessary validation rules
        ]);

        $response = $this->repository->store($request->__authenticatedUser, $data);

        return response($response);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param int $id
     * @param Request $request
     * @return mixed
     */
    public function update($id, Request $request)
    {
        $data = $request->validate([
            // Add necessary validation rules
        ]);

        $response = $this->repository->updateJob($id, array_except($data, ['_token', 'submit']), $request->__authenticatedUser);

        return response($response);
    }

    /**
     * Send immediate job email.
     *
     * @param Request $request
     * @return mixed
     */
    public function immediateJobEmail(Request $request)
    {
        $data = $request->validate([
            // Add necessary validation rules
        ]);

        $response = $this->repository->storeJobEmail($data);

        return response($response);
    }

    /**
     * Get user job history.
     *
     * @param Request $request
     * @return mixed
     */
    public function getHistory(Request $request)
    {
        $user_id = $request->get('user_id');

        if ($user_id) {
            $response = $this->repository->getUsersJobsHistory($user_id, $request);

            return response($response);
        }

        return response(null);
    }

    /**
     * Accept a job.
     *
     * @param Request $request
     * @return mixed
     */
    public function acceptJob(Request $request)
    {
        $data = $request->validate([
            // Add necessary validation rules
        ]);

        $response = $this->repository->acceptJob($data, $request->__authenticatedUser);

        return response($response);
    }

    /**
     * Accept a job by ID.
     *
     * @param Request $request
     * @return mixed
     */
    public function acceptJobWithId(Request $request)
    {
        $job_id = $request->validate([
            'job_id' => 'required|integer',
        ]);

        $response = $this->repository->acceptJobWithId($job_id, $request->__authenticatedUser);

        return response($response);
    }

    /**
     * Cancel a job.
     *
     * @param Request $request
     * @return mixed
     */
    public function cancelJob(Request $request)
    {
        $data = $request->validate([
            // Add necessary validation rules
        ]);

        $response = $this->repository->cancelJobAjax($data, $request->__authenticatedUser);

        return response($response);
    }

    /**
     * End a job.
     *
     * @param Request $request
     * @return mixed
     */
    public function endJob(Request $request)
    {
        $data = $request->validate([
            // Add necessary validation rules
        ]);

        $response = $this->repository->endJob($data);

        return response($response);
    }

    /**
     * Handle customer not calling.
     *
     * @param Request $request
     * @return mixed
     */
    public function customerNotCall(Request $request)
    {
        $data = $request->validate([
            // Add necessary validation rules
        ]);

        $response = $this->repository->customerNotCall($data);

        return response($response);
    }

    /**
     * Get potential jobs for a user.
     *
     * @param Request $request
     * @return mixed
     */
    public function getPotentialJobs(Request $request)
    {
        $response = $this->repository->getPotentialJobs($request->__authenticatedUser);

        return response($response);
    }

    /**
     * Update distance feed.
     *
     * @param Request $request
     * @return mixed
     */
    public function distanceFeed(Request $request)
    {
        $data = $request->validate([
            'distance' => 'nullable|string',
            'time' => 'nullable|string',
            'jobid' => 'required|integer',
            'session_time' => 'nullable|string',
            'flagged' => 'required|boolean',
            'manually_handled' => 'required|boolean',
            'by_admin' => 'required|boolean',
            'admincomment' => 'nullable|string',
        ]);

        $distanceUpdate = [
            'distance' => $data['distance'] ?? '',
            'time' => $data['time'] ?? '',
        ];

        $jobUpdate = [
            'admin_comments' => $data['admincomment'] ?? '',
            'flagged' => $data['flagged'] ? 'yes' : 'no',
            'manually_handled' => $data['manually_handled'] ? 'yes' : 'no',
            'by_admin' => $data['by_admin'] ? 'yes' : 'no',
            'session_time' => $data['session_time'] ?? '',
        ];

        Distance::where('job_id', $data['jobid'])->update($distanceUpdate);
        Job::where('id', $data['jobid'])->update($jobUpdate);

        return response('Record updated!');
    }

    /**
     * Reopen a job.
     *
     * @param Request $request
     * @return mixed
     */
    public function reopen(Request $request)
    {
        $data = $request->validate([
            // Add necessary validation rules
        ]);

        $response = $this->repository->reopen($data);

        return response($response);
    }

    /**
     * Resend job notifications.
     *
     * @param Request $request
     * @return mixed
     */
    public function resendNotifications(Request $request)
    {
        $data = $request->validate([
            'jobid' => 'required|integer',
        ]);

        $job = $this->repository->find($data['jobid']);
        $job_data = $this->repository->jobToData($job);
        $this->repository->sendNotificationTranslator($job, $job_data, '*');

        return response(['success' => 'Push sent']);
    }

    /**
     * Resend job SMS notifications.
     *
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function resendSMSNotifications(Request $request)
    {
        $data = $request->validate([
            'jobid' => 'required|integer',
        ]);

        $job = $this->repository->find($data['jobid']);

        try {
            $this->repository->sendSMSNotificationToTranslator($job);
            return response(['success' => 'SMS sent']);
        } catch (\Exception $e) {
            return response(['success' => $e->getMessage()]);
        }
    }
}

