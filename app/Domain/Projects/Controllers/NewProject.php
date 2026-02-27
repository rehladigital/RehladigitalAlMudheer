<?php

namespace Leantime\Domain\Projects\Controllers;

use Leantime\Core\Controller\Controller;
use Leantime\Core\Controller\Frontcontroller;
use Leantime\Core\Mailer as MailerCore;
use Leantime\Core\Support\FromFormat;
use Leantime\Domain\Auth\Models\Roles;
use Leantime\Domain\Auth\Services\Auth;
use Leantime\Domain\Clients\Repositories\Clients as ClientRepository;
use Leantime\Domain\Menu\Repositories\Menu as MenuRepository;
use Leantime\Domain\Projects\Repositories\Projects as ProjectRepository;
use Leantime\Domain\Projects\Services\Projects as ProjectService;
use Leantime\Domain\Queue\Repositories\Queue as QueueRepository;
use Leantime\Domain\Users\Repositories\Users as UserRepository;

class NewProject extends Controller
{
    private ProjectRepository $projectRepo;

    private MenuRepository $menuRepo;

    private UserRepository $userRepo;

    private ClientRepository $clientsRepo;

    private QueueRepository $queueRepo;

    private ProjectService $projectService;

    /**
     * init - initialize private variables
     */
    public function init(
        ProjectRepository $projectRepo,
        MenuRepository $menuRepo,
        UserRepository $userRepo,
        ClientRepository $clientsRepo,
        QueueRepository $queueRepo,
        ProjectService $projectService
    ) {
        $this->projectRepo = $projectRepo;
        $this->menuRepo = $menuRepo;
        $this->userRepo = $userRepo;
        $this->clientsRepo = $clientsRepo;
        $this->queueRepo = $queueRepo;
        $this->projectService = $projectService;
    }

    /**
     * run - display template and edit data
     */
    public function run()
    {
        Auth::authOrRedirect([Roles::$owner, Roles::$admin, Roles::$manager], true);

        if (! session()->exists('lastPage')) {
            session(['lastPage' => BASE_URL.'/projects/showAll']);
        }

        $msgKey = '';
        $values = [
            'id' => '',
            'name' => '',
            'details' => '',
            'clientId' => (int) (session('userdata.clientId') ?? 0),
            'hourBudget' => '',
            'assignedUsers' => [session('userdata.id')],
            'dollarBudget' => '',
            'state' => '',
            'menuType' => MenuRepository::DEFAULT_MENU,
            'type' => 'project',
            'parent' => $_GET['parent'] ?? '',
            'psettings' => '',
            'start' => '',
            'end' => '',
        ];

        if (isset($_POST['save']) === true) {

            if (! isset($_POST['hourBudget']) || $_POST['hourBudget'] == '' || $_POST['hourBudget'] == null) {
                $hourBudget = '0';
            } else {
                $hourBudget = $_POST['hourBudget'];
            }

            if (isset($_POST['editorId']) && count($_POST['editorId'])) {
                $assignedUsers = $_POST['editorId'];
            } else {
                $assignedUsers = [];
            }

            $mailer = app()->make(MailerCore::class);

            $values = [
                'name' => $_POST['name'] ?? '',
                'details' => $_POST['details'] ?? '',
                'clientId' => (int) ($_POST['clientId'] ?? 0),
                'hourBudget' => $hourBudget,
                'assignedUsers' => $assignedUsers,
                'dollarBudget' => $_POST['dollarBudget'] ?? 0,
                'state' => $_POST['projectState'],
                'psettings' => $_POST['globalProjectUserAccess'],
                'menuType' => $_POST['menuType'] ?? 'default',
                'type' => $_POST['type'] ?? 'project',
                'parent' => $_POST['parent'] ?? '',
                'start' => format(value: $_POST['start'], fromFormat: FromFormat::UserDateStartOfDay)->isoDateTime(),
                'end' => $_POST['end'] ? format(value: $_POST['end'], fromFormat: FromFormat::UserDateEndOfDay)->isoDateTime() : '',
            ];

            $userRole = (string) (session('userdata.role') ?? '');
            $currentUserClientId = (int) (session('userdata.clientId') ?? 0);
            if ($userRole === Roles::$manager && $currentUserClientId > 0) {
                // Client manager should always create projects under their assigned client.
                $values['clientId'] = $currentUserClientId;
            }

            if ($values['name'] === '') {
                $this->tpl->setNotification($this->language->__('notification.no_project_name'), 'error');
            } elseif ((int) $values['clientId'] <= 0) {
                $this->tpl->setNotification($this->language->__('notification.no_client'), 'error');
            } else {
                $normalizedName = mb_strtolower(trim((string) $values['name']));
                $clientProjects = $this->projectRepo->getClientProjects((int) $values['clientId']);
                foreach ($clientProjects as $existingProject) {
                    $existingName = mb_strtolower(trim((string) ($existingProject['name'] ?? '')));
                    if ($existingName !== '' && $existingName === $normalizedName) {
                        $existingId = (int) ($existingProject['id'] ?? 0);
                        if ($existingId > 0) {
                            $this->tpl->setNotification('A project with this name already exists for this client.', 'error');

                            return Frontcontroller::redirect(BASE_URL.'/projects/showProject/'.$existingId);
                        }
                    }
                }

                $projectName = $values['name'];
                $id = $this->projectRepo->addProject($values);
                $this->projectService->changeCurrentSessionProject($id);

                $users = $this->projectRepo->getUsersAssignedToProject($id);

                $mailer->setContext('project_created');
                $mailer->setSubject($this->language->__('email_notifications.project_created_subject'));
                $actual_link = BASE_URL.'/projects/showProject/'.$id.'';
                $message = sprintf($this->language->__('email_notifications.project_created_message'), $actual_link, $id, strip_tags($projectName), session('userdata.name'));
                $mailer->setHtml($message);

                $to = [];

                foreach ($users as $user) {
                    if ($user['notifications'] != 0) {
                        $to[] = $user['username'];
                    }
                }

                // $mailer->sendMail($to, session("userdata.name"));
                // NEW Queuing messaging system
                $this->queueRepo->queueMessageToUsers($to, $message, $this->language->__('email_notifications.project_created_subject'), $id);

                // Take the old value to avoid nl character
                $values['details'] = $_POST['details'];

                $this->tpl->sendConfetti();
                $this->tpl->setNotification(sprintf($this->language->__('notifications.project_created_successfully'), BASE_URL.'/leancanvas/simpleCanvas/'), 'success', 'project_created');

                return Frontcontroller::redirect(BASE_URL.'/projects/showProject/'.$id);
            }

            $this->tpl->assign('project', $values);
        }

        $this->tpl->assign('menuTypes', $this->menuRepo->getMenuTypes());
        $this->tpl->assign('project', $values);
        $this->tpl->assign('availableUsers', $this->userRepo->getAll());
        $clients = $this->clientsRepo->getAll();
        $userRole = (string) (session('userdata.role') ?? '');
        $currentUserClientId = (int) (session('userdata.clientId') ?? 0);
        if ($userRole === Roles::$manager && $currentUserClientId > 0) {
            $ownClient = $this->clientsRepo->getClient($currentUserClientId);
            if (is_array($ownClient)) {
                $clients = [[
                    'id' => $ownClient['id'],
                    'name' => $ownClient['name'],
                    'internet' => $ownClient['internet'] ?? '',
                    'numberOfProjects' => $ownClient['numberOfProjects'] ?? 0,
                ]];
                $values['clientId'] = $ownClient['id'];
                $this->tpl->assign('project', $values);
            }
        }
        $this->tpl->assign('clients', $clients);
        $this->tpl->assign('projectTypes', $this->projectService->getProjectTypes());

        $this->tpl->assign('info', $msgKey);

        return $this->tpl->display('projects.newProject');
    }
}
