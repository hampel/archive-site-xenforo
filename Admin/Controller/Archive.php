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


	/**
	 * @return \Hampel\ArchiveSite\Repository\Archive
	 */
	protected function getArchiveRepo()
	{
		return $this->repository('Hampel\ArchiveSite:Archive');
	}
}