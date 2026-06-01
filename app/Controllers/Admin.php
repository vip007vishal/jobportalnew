<?php

namespace App\Controllers;

use App\Models\UserModel;
use App\Models\JobModel;
use App\Models\ApplicationModel;
use App\Models\ApplicationLogModel;
use App\Models\SkillModel;

class Admin extends BaseController
{
    public function login()
    {
        $session = session();

        $model = new UserModel();

        $email = $this->request->getPost('email');

        $password = $this->request->getPost('password');

        $user = $model
            ->where('email', $email)
            ->first();

        if (!$user) {

            return redirect()
                ->back()
                ->with('error', 'Invalid Email');
        }

        if ($user['status'] != 'active') {

            return redirect()
                ->back()
                ->with('error', 'Access Revoked or Your Account is Deactivated');
        }

        if (!password_verify($password, $user['password'])) {

            return redirect()
                ->back()
                ->with('error', 'Invalid Password');
        }

        $session->set([
            'user_id' => $user['id'],
            'user_name' => $user['name'],
            'user_role' => $user['role'],
            'logged_in' => true
        ]);

        return redirect()->to(base_url('admin/analytics'));
    }

    public function analytics()
    {
        $data = $this->prepareDashboardData();
        return view('admin/analytics', $data);
    }

    /**
     * Prepare shared dashboard data used by the split pages.
     */
    private function prepareDashboardData()
    {
        if (!session()->get('logged_in')) {
            return redirect()->to('admin/login');
        }

        $skillModel = new SkillModel();
        $allSkills = $skillModel->findAll();

        $applicationModel = new ApplicationModel();
        $jobModel = new JobModel();
        $userModel = new UserModel();

        $currentUser = $userModel->find(session()->get('user_id'));

        $search = $this->request->getGet('search');
        $status = $this->request->getGet('status');

        $builder = $applicationModel
            ->select('
                applications.id,
                applications.role_id,
                applications.assigned_user_id,
                applications.full_name,
                applications.email,
                applications.mobile,
                applications.technical_skills,
                applications.skills_summary,
                applications.status,
                applications.resume,
                job_roles.role_name,
                job_roles.technical_skills as required_skills,
                users.name as assigned_user_name,
                users.role as assigned_user_role
            ')
            ->join('job_roles', 'job_roles.id = applications.role_id');

        $builder->join('users', 'users.id = applications.assigned_user_id', 'left');

        if (session()->get('user_role') != 'admin') {
            if ($currentUser['view_global'] == 't') {
                // HR/TL with global view can see all applications
            } elseif ($currentUser['view_own'] == 't') {
                $builder->where('applications.assigned_user_id', session()->get('user_id'));
            } else {
                $builder->where('1 = 0');
            }
        }

        if ($search) {
            $builder->groupStart()
                ->like('full_name', $search)
                ->orLike('email', $search)
                ->orLike('mobile', $search)
                ->orLike('role_name', $search)
                ->groupEnd();
        }

        if ($status) {
            $builder->where('applications.status', $status);
        }

        $applications = $builder->orderBy('applications.id', 'DESC')->findAll();

        foreach ($applications as &$application) {
            $candidateSkillsText = $application['technical_skills'] ?? '';
            if (empty($candidateSkillsText)) {
                $candidateSkillsText = $application['skills_summary'] ?? '';
            }

            $application['skill_match'] = $this->calculateSkillMatch(
                $application['required_skills'] ?? '',
                $candidateSkillsText
            );
        }
        unset($application);

        $jobs = $jobModel->findAll();

        $statusCounts = [
            'Applied' => 0,
            'Waiting' => 0,
            'Hired' => 0,
            'Rejected' => 0,
            'Hold' => 0
        ];

        foreach ($applications as $app) {
            if (isset($statusCounts[$app['status']])) {
                $statusCounts[$app['status']]++;
            }
        }

        $roleCounts = [];
        foreach ($applications as $app) {
            $role = $app['role_name'];
            if (!isset($roleCounts[$role])) {
                $roleCounts[$role] = 0;
            }
            $roleCounts[$role]++;
        }

        $users = $userModel->whereIn('role', ['hr', 'tl'])->findAll();

        $data = [];
        $data['allSkills'] = $allSkills;
        $data['applications'] = $applications;
        $data['jobs'] = $jobs;
        $data['statusCounts'] = $statusCounts;
        $data['roleCounts'] = $roleCounts;
        $data['userRole'] = session()->get('user_role');
        $data['access'] = $currentUser['access'];
        $data['canViewOwn'] = $currentUser['view_own'];
        $data['canViewGlobal'] = $currentUser['view_global'];
        $data['canUpdateStatus'] = $currentUser['update_status'];
        $data['users'] = $users;

        return $data;
    }

    private function normalizeSkillTokens(string $text): array
    {
        $text = trim(strtolower($text));

        if ($text === '') {
            return [];
        }

        $items = preg_split('/[\n\r,;]+/', $text);
        $tokens = [];

        foreach ($items as $item) {
            $item = trim(preg_replace('/\s+/u', ' ', $item));

            if ($item === '') {
                continue;
            }

            $tokens[] = $item;

            if (strpos($item, ' ') !== false) {
                $words = preg_split('/[^\p{L}\p{N}]+/u', $item);
                foreach ($words as $word) {
                    $word = trim($word);
                    if ($word !== '') {
                        $tokens[] = $word;
                    }
                }
            }
        }

        return array_values(array_unique($tokens));
    }

    private function calculateSkillMatch(string $requiredSkillsText, string $candidateSkillsText): int
    {
        $requiredSkills = $this->normalizeSkillTokens($requiredSkillsText);
        $candidateSkills = $this->normalizeSkillTokens($candidateSkillsText);

        if (empty($requiredSkills) || empty($candidateSkills)) {
            return 0;
        }

        $matched = 0;

        foreach ($requiredSkills as $requiredSkill) {
            if ($requiredSkill === '') {
                continue;
            }

            if (in_array($requiredSkill, $candidateSkills, true)) {
                $matched++;
                continue;
            }

            foreach ($candidateSkills as $candidateSkill) {
                if ($candidateSkill === '') {
                    continue;
                }

                if (function_exists('mb_stripos')) {
                    $needle = mb_strtolower($requiredSkill, 'UTF-8');
                    $haystack = mb_strtolower($candidateSkill, 'UTF-8');
                } else {
                    $needle = strtolower($requiredSkill);
                    $haystack = strtolower($candidateSkill);
                }

                if ($needle === $haystack) {
                    $matched++;
                    break;
                }

                if (trim($needle) !== '' && trim($haystack) !== '' && (
                    strpos($haystack, $needle) !== false ||
                    strpos($needle, $haystack) !== false
                )) {
                    $matched++;
                    break;
                }
            }
        }

        return (int) round(($matched / count($requiredSkills)) * 100);
    }

    public function updateSettings()
    {
        $userModel = new UserModel();

        $userId = $this->request->getPost('user_id');

        $data = [
            'view_own'      => $this->request->getPost('view_own') ? 't' : 'f',
            'view_global'   => $this->request->getPost('view_global') ? 't' : 'f',
            'update_status' => $this->request->getPost('update_status') ? 't' : 'f',
        ];

        $userModel->update($userId, $data);

        return redirect()->back()->with(
            'success',
            'User settings updated successfully'
        );
    }


    public function applicationsPage()
    {
        $data = $this->prepareDashboardData();
        return view('admin/applications', $data);
    }

    public function usersPage()
    {
        $data = $this->prepareDashboardData();
        return view('admin/users', $data);
    }

    public function rolesPage()
    {
        $data = $this->prepareDashboardData();
        return view('admin/roles', $data);
    }

    public function addRole()
    {
        if (!session()->get('logged_in')) {

            return redirect()->to('admin/login');
        }

        if (session()->get('user_role') != 'admin') {

            return redirect()->back();
        }

        $jobModel = new JobModel();

        $roleName = $this->request->getPost('role_name');

        $description = $this->request->getPost('description');

        $status = $this->request->getPost('status');

        // checkbox skills
        $requiredSkills = $this->request->getPost('required_skills');

        // convert array to comma separated string
        $technicalSkills = '';

        if (!empty($requiredSkills)) {

            $technicalSkills = implode(',', $requiredSkills);
        }

        $jobModel->save([

            'role_name' => $roleName,

            'description' => $description,

            'technical_skills' => $technicalSkills,

            'status' => $status
        ]);

        return redirect()->to(base_url('admin/rolespage'));
    }

    public function assignApplication()
    {
        if (!session()->get('logged_in')) {

            return redirect()->to('admin/login');
        }

        if (session()->get('user_role') != 'admin') {

            return redirect()->back();
        }

        $applicationId = $this->request->getPost('application_id');

        $userId = $this->request->getPost('user_id');

        $applicationModel = new ApplicationModel();

        $userModel = new UserModel();

        $user = $userModel->find($userId);

        if (!$user) {

            return $this->response->setJSON([
                'success' => false,
                'message' => 'User not found'
            ]);
        }

        if (
            $user['role'] != 'hr' &&
            $user['role'] != 'tl'
        ) {

            return $this->response->setJSON([
                'success' => false,
                'message' => 'Invalid role'
            ]);
        }

        $applicationModel->update($applicationId, [
            'assigned_user_id' => $userId
        ]);

        $logModel = new ApplicationLogModel();

        $logModel->save([
            'application_id' => $applicationId,
            'user_id' => session()->get('user_id'),
            'action' => 'application_assigned',
            'description' => 'Assigned to ' . $user['name']
        ]);

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Application Assigned'
        ]);
    }


    public function getAssignableUsers()
    {
        if (!session()->get('logged_in')) {

            return redirect()->to('admin/login');
        }

        if (session()->get('user_role') != 'admin') {

            return redirect()->back();
        }

        $userModel = new UserModel();

        $users = $userModel
            ->whereIn('role', ['hr', 'tl'])
            ->where('status', 'active')
            ->findAll();

        return $this->response->setJSON($users);
    }

    public function updateStatus($id)
    {
        if (!session()->get('logged_in')) {

            return redirect()->to('admin/login');
        }

        $applicationModel = new ApplicationModel();

        $status = $this->request->getPost('status');

        $application = $applicationModel
            ->select('
        applications.*,
        job_roles.role_name,
        job_roles.technical_skills,
        users.name as assigned_user_name,
        users.role as assigned_user_role
    ')
            ->join(
                'job_roles',
                'job_roles.id = applications.role_id'
            )
            ->join(
                'users',
                'users.id = applications.assigned_user_id',
                'left'
            )
            ->where(
                'applications.id',
                $id
            )
            ->first();

        if (
            session()->get('user_role') != 'admin' &&
            $application['assigned_user_id'] != session()->get('user_id')
        ) {

            return $this->response->setJSON([
                'success' => false,
                'message' => 'Unauthorized'
            ]);
        }

        $applicationModel->update($id, [
            'status' => $status
        ]);

        $logModel = new ApplicationLogModel();

        $logModel->save([
            'application_id' => $id,
            'user_id' => session()->get('user_id'),
            'action' => 'status_updated',
            'description' => 'Status changed to ' . $status
        ]);

        $email = \Config\Services::email();

        $email->setFrom(
            'vishal15v2006@gmail.com',
            'Job Portal'
        );

        $email->setTo(
            $application['email']
        );

        $email->setSubject(
            'Application Status Updated'
        );

        $message = '';

        if ($status == 'Hired') {

            $message = "
                <h2>Congratulations!</h2>
                <p>You have been hired.</p>
            ";
        } elseif ($status == 'Rejected') {

            $message = "
                <h2>Application Rejected</h2>
                <p>Unfortunately you were not selected.</p>
            ";
        } elseif ($status == 'Waiting') {

            $message = "
                <h2>Application Waiting</h2>
                <p>Your application is under review.</p>
            ";
        } else {

            $message = "
                <h2>Application On Hold</h2>
                <p>Your application is currently on hold.</p>
            ";
        }

        $email->setMessage($message);

        $email->send();

        $logModel->save([
            'application_id' => $id,
            'user_id' => session()->get('user_id'),
            'action' => 'email_sent',
            'description' => 'Status email sent to applicant'
        ]);

        return $this->response->setJSON([
            'success' => true,
            'status' => $status
        ]);
    }

    public function applicationLogs($applicationId)
    {
        if (!session()->get('logged_in')) {

            return redirect()->to('admin/login');
        }

        $logModel = new ApplicationLogModel();

        $logs = $logModel
            ->select('
            application_logs.*,
            users.name as user_name,
            users.role as user_role
        ')
            ->join(
                'users',
                'users.id = application_logs.user_id',
                'left'
            )
            ->where(
                'application_logs.application_id',
                $applicationId
            )
            ->orderBy(
                'application_logs.id',
                'DESC'
            )
            ->findAll();

        return $this->response->setJSON($logs);
    }

    public function users()
    {
        if (!session()->get('logged_in')) {

            return redirect()->to('admin/login');
        }

        if (session()->get('user_role') != 'admin') {

            return redirect()->back();
        }

        $userModel = new UserModel();

        $users = $userModel
            ->whereIn('role', ['hr', 'tl'])
            ->findAll();

        return $this->response->setJSON($users);
    }

    public function createUser()
    {
        if (!session()->get('logged_in')) {

            return redirect()->to('admin/login');
        }

        if (session()->get('user_role') != 'admin') {

            return redirect()->back();
        }

        $userModel = new UserModel();

        $name = $this->request->getPost('name');

        $email = $this->request->getPost('email');

        $password = $this->request->getPost('password');

        $role = $this->request->getPost('role');

        $access = $this->request->getPost('access');

        if (
            $role != 'hr' &&
            $role != 'tl'
        ) {

            return $this->response->setJSON([
                'success' => false,
                'message' => 'Invalid Role'
            ]);
        }

        $existing = $userModel
            ->where('email', $email)
            ->first();

        if ($existing) {

            return $this->response->setJSON([
                'success' => false,
                'message' => 'Email Already Exists'
            ]);
        }

        $userModel->save([
            'name' => $name,
            'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
            'status' => 'active',
            'access' => 't'
        ]);

        return $this->response->setJSON([
            'success' => true,
            'message' => 'User Created'
        ]);
    }

    public function updateAccess($id)
    {
        $userModel = new UserModel();

        $user = $userModel->find($id);

        $newAccess = $user['access'] === 't' ? 'f' : 't';

        $userModel->update($id, [
            'access' => $newAccess
        ]);

        return redirect()->back();
    }

    public function applicantDetails($id)
    {
        if (!session()->get('logged_in')) {

            return redirect()->to('admin/login');
        }

        $applicationModel = new ApplicationModel();

        $application = $applicationModel
            ->select('
                applications.*,
                job_roles.role_name,
                job_roles.technical_skills as required_skills
            ')
            ->join(
                'job_roles',
                'job_roles.id = applications.role_id'
            )
            ->where('applications.id', $id)
            ->first();

        if (!$application) {

            return redirect()->back();
        }

        if (
            session()->get('user_role') != 'admin' &&
            $application['assigned_user_id'] != session()->get('user_id')
        ) {

            return redirect()->back();
        }

        if (session()->get('user_role') == 'tl') {

            $application['mobile'] = null;

            $application['address'] = null;

            $application['email'] = null;

            $application['nationality'] = null;

            $application['dob'] = null;
        }

        $data['application'] = $application;

        return view(
            'admin/applicant_details',
            $data
        );
    }

    public function updateRole($id)
    {
        if (!session()->get('logged_in')) {

            return redirect()->to('admin/login');
        }

        if (session()->get('user_role') != 'admin') {

            return redirect()->back();
        }

        $jobModel = new JobModel();

        $job = $jobModel->find($id);

        if (!$job) {

            return redirect()->back();
        }

        $requiredSkills = $this->request->getPost('required_skills');
        $requiredSkillsText = $this->request->getPost('required_skills_text');

        $technicalSkills = $job['technical_skills'];

        if (!empty($requiredSkills)) {
            $technicalSkills = implode(',', $requiredSkills);
        } elseif ($requiredSkillsText !== null && trim($requiredSkillsText) !== '') {
            $technicalSkills = trim($requiredSkillsText);
        }

        $data = [
            'role_name' => $this->request->getPost('role_name') ?? $job['role_name'],
            'description' => $this->request->getPost('description') ?? $job['description'],
            'status' => $this->request->getPost('status') ?? $job['status'],
            'technical_skills' => $technicalSkills
        ];

        $jobModel->update($id, $data);

        return redirect()->to(base_url('admin/analytics'));
    }

    public function deleteRole($id)
    {
        if (!session()->get('logged_in')) {

            return redirect()->to('admin/login');
        }

        if (session()->get('user_role') != 'admin') {

            return redirect()->back();
        }

        $jobModel = new JobModel();

        $job = $jobModel->find($id);

        if (!$job) {

            return redirect()->back();
        }

        try {

            $jobModel->delete($id);

            return redirect()
                ->to(base_url('admin/roles'))
                ->with('success', 'Role deleted successfully');
        } catch (\Exception $e) {

            return redirect()
                ->back()
                ->with('error', 'Cannot delete role with existing applications. Please delete or reassign applications first.');
        }
    }

    public function addSkill()
    {
        if (!session()->get('logged_in')) {

            return redirect()->to('admin/login');
        }

        if (session()->get('user_role') != 'admin') {

            return redirect()->back();
        }

        $skillModel = new SkillModel();

        $skillName = trim(
            $this->request->getPost('skill_name')
        );

        if (empty($skillName)) {

            return redirect()->back();
        }

        $existing = $skillModel
            ->where(
                'skill_name',
                $skillName
            )
            ->first();

        if (!$existing) {

            $skillModel->save([
                'skill_name' => $skillName
            ]);
        }

        return redirect()->to(
            base_url('admin/roles')
        );
    }

    public function deleteSkill($id)
    {
        if (!session()->get('logged_in')) {

            return redirect()->to('admin/login');
        }

        if (session()->get('user_role') != 'admin') {

            return redirect()->back();
        }

        $skillModel = new SkillModel();

        $skill = $skillModel->find($id);

        if (!$skill) {

            return redirect()->back();
        }

        $skillModel->delete($id);

        return redirect()->to(
            base_url('admin/roles')
        );
    }

    public function logout()
    {
        session()->destroy();

        return redirect()->to('admin/login');
    }
}
