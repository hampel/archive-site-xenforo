<?php

namespace Hampel\ArchiveSite\Admin\Controller;

use XF\Admin\Controller\AbstractController;
use XF\Entity\User;
use XF\Mvc\FormAction;
use XF\Mvc\ParameterBag;

class Archive extends AbstractController
{
	protected function preDispatchController($action, ParameterBag $params)
	{
		switch (strtolower($action))
		{
			case 'index':
				break;

			default:
				$this->assertSuperAdmin();
		}
	}

	public function actionIndex()
	{
		return $this->view('Hampel\ArchiveSite:Users', 'hampel_archivesite_users');
	}

	public function actionProtectedUsers()
	{
		$this->setSectionContext('hampelArchiveSiteProtectedUsers');

		$page = $this->filterPage();
		$perPage = 20;

		$finder = $this->getArchiveRepo()->protectedUsers();
		$finder->limitByPage($page, $perPage);
		$total = $finder->total();
		$users = $finder->fetch();

		$viewParams = compact('users', 'total', 'page', 'perPage');
		return $this->view('Hampel\ArchiveSite:Tools\ProtectedUsers', 'hampel_archivesite_protected_users', $viewParams);

	}

	public function actionArchivedUsers()
	{
		$this->setSectionContext('hampelArchiveSiteArchivedUsers');

		$page = $this->filterPage();
		$perPage = 20;

		$finder = $this->getArchiveRepo()->archivedUsers();
		$finder->limitByPage($page, $perPage);
		$total = $finder->total();
		$users = $finder->fetch();

		$viewParams = compact('users', 'total', 'page', 'perPage');
		return $this->view('Hampel\ArchiveSite:Tools\ArchivedUsers', 'hampel_archivesite_archived_users', $viewParams);

	}

	public function actionActiveUsers()
	{
		$this->setSectionContext('hampelArchiveSiteActiveUsers');

		$page = $this->filterPage();
		$perPage = 20;

		$finder = $this->getArchiveRepo()->activeUsers();
		$finder->limitByPage($page, $perPage, 5);
		$total = $finder->total();
		$users = $finder->fetch()->filter(function(User $user) {
			return !$user->is_super_admin;
		});

		$users = $users->slice(0, $perPage);

		$viewParams = compact('users', 'total', 'page', 'perPage');
		return $this->view('Hampel\ArchiveSite:Tools\ActiveUsers', 'hampel_archivesite_active_users', $viewParams);
	}

	public function actionArchiveUsers()
	{
		$this->setSectionContext('hampelArchiveSiteArchiveUsers');

		$finder = $this->getArchiveRepo()->protectedUsers();
		$protected = $finder->total();

		$finder = $this->getArchiveRepo()->archivedUsers();
		$archived = $finder->total();

		$finder = $this->getArchiveRepo()->activeUsers();
		$activeUsers = $finder->fetch()->filter(function(User $user) {
			return !$user->is_super_admin;
		});
		$active = $activeUsers->count();

		$viewParams = compact('protected', 'active', 'archived');
		return $this->view('ampel\ArchiveSite:Tools\ArchiveUsers', 'hampel_archivesite_archive_users', $viewParams);
	}

	public function actionArchiveUsersAction()
	{
		$this->setSectionContext('hampelArchiveSiteArchiveUsers');

		$this->assertPostOnly();

		$actions = $this->filter('actions', 'array');

		if ($this->request->exists('confirm_archive') && empty($actions['archive']))
		{
			return $this->error(\XF::phrase('hampel_archivesite_you_must_confirm_archive_to_proceed'));
		}

		$finder = $this->getArchiveRepo()->protectedUsers();
		$protectedUsers = $finder->pluckFrom('user_id')->fetch();

		$this->app->jobManager()->enqueueUnique('archiveUsers', 'Hampel\ArchiveSite:ArchiveUsers', [
			'protectedUsers' => $protectedUsers->toArray()
		]);

		$reply = $this->redirect(
			$this->buildLink('tools/run-job', null, [
				'only' => 'archiveUsers',
				'_xfRedirect' => $this->buildLink('archive/archive-users')
			])
		);
		$reply->setPageParam('skipManualJobRun', true);
		return $reply;
	}

	/**
	 * @return \Hampel\ArchiveSite\Repository\ArchiveUsers
	 */
	protected function getArchiveRepo()
	{
		return $this->repository('Hampel\ArchiveSite:ArchiveUsers');
	}
}