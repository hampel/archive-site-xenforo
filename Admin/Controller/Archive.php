<?php

namespace Hampel\ArchiveSite\Admin\Controller;

use XF\Admin\Controller\AbstractController;
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

		$users = $this->getArchiveRepo()->protectedUsers();

		$viewParams = compact('users');
		return $this->view('Hampel\ArchiveSite:Tools\ProtectedUsers', 'hampel_archivesite_protected_users', $viewParams);

	}

	public function actionArchivedUsers()
	{
		$this->setSectionContext('hampelArchiveSiteArchivedUsers');

		$users = $this->getArchiveRepo()->archivedUsers();

		$viewParams = compact('users');
		return $this->view('Hampel\ArchiveSite:Tools\ArchivedUsers', 'hampel_archivesite_archived_users', $viewParams);

	}

	public function actionActiveUsers()
	{
		$this->setSectionContext('hampelArchiveSiteActiveUsers');

		$users = $this->getArchiveRepo()->activeUsers();

		$viewParams = compact('users');
		return $this->view('Hampel\ArchiveSite:Tools\ActiveUsers', 'hampel_archivesite_active_users', $viewParams);
	}

//	public function actionProtectedUsers()
//	{
//		$criteria = $this->filter('criteria', 'array');
//		$order = $this->filter('order', 'str');
//		$direction = $this->filter('direction', 'str');
//
//		$page = $this->filterPage();
//		$perPage = 20;
//
//		$showingAll = $this->filter('all', 'bool');
//		if ($showingAll)
//		{
//			$page = 1;
//			$perPage = 5000;
//		}
//
//		if (!$criteria)
//		{
//			$this->setSectionContext('listAllUsers');
//		}
//		else
//		{
//			$this->setSectionContext('searchForUsers');
//		}
//
//		$searcher = $this->searcher('XF:User', $criteria);
//
//		if ($order && !$direction)
//		{
//			$direction = $searcher->getRecommendedOrderDirection($order);
//		}
//
//		$searcher->setOrder($order, $direction);
//
//		$finder = $searcher->getFinder();
//		$finder->limitByPage($page, $perPage);
//
//		$filter = $this->filter('_xfFilter', [
//			'text' => 'str',
//			'prefix' => 'bool'
//		]);
//		if (strlen($filter['text']))
//		{
//			$finder->where('username', 'LIKE', $finder->escapeLike($filter['text'], $filter['prefix'] ? '?%' : '%?%'));
//		}
//
//		$total = $finder->total();
//		$users = $finder->fetch();
//
//		$this->assertValidPage($page, $perPage, $total, 'users/list');
//
//		if (!strlen($filter['text']) && $total == 1 && ($user = $users->first()))
//		{
//			return $this->redirect($this->buildLink('users/edit', $user));
//		}
//
//		$viewParams = [
//			'users' => $users,
//
//			'total' => $total,
//			'page' => $page,
//			'perPage' => $perPage,
//
//			'showingAll' => $showingAll,
//			'showAll' => (!$showingAll && $total <= 5000),
//
//			'criteria' => $searcher->getFilteredCriteria(),
//			'filter' => $filter['text'],
//			'sortOptions' => $searcher->getOrderOptions(),
//			'order' => $order,
//			'direction' => $direction
//		];
//		return $this->view('XF:User\Listing', 'user_list', $viewParams);
//	}


	/**
	 * @param string $id
	 * @param array|string|null $with
	 * @param null|string $phraseKey
	 *
	 * @return \XF\Entity\User
	 */
	protected function assertUserExists($id, $with = null, $phraseKey = null)
	{
		return $this->assertRecordExists('XF:User', $id, $with, $phraseKey);
	}

	protected function assertCanEditUser(\XF\Entity\User $user)
	{
		if ($user->is_admin
			&& $user->Admin->is_super_admin
			&& !\XF::visitor()->Admin->is_super_admin
		)
		{
			throw $this->exception(
				$this->error(\XF::phrase('you_must_be_super_administrator_to_edit_user'))
			);
		}
	}

	/**
	 * @return \XF\Repository\User
	 */
	protected function getUserRepo()
	{
		return $this->repository('XF:User');
	}

	/**
	 * @return \Hampel\ArchiveSite\Repository\Archive
	 */
	protected function getArchiveRepo()
	{
		return $this->repository('Hampel\ArchiveSite:Archive');
	}
}