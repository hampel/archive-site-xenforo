<?php namespace Hampel\ArchiveSite;

use XF\Db\Schema\Alter;
use XF\Db\Schema\Create;
use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerUpgradeTrait;

class Setup extends AbstractSetup
{
	use StepRunnerUpgradeTrait;

	public function install(array $stepParams = [])
	{
		$this->createTables();
	}

    // upgrade to 2.0.0
    public function upgrade2000070Step1(array $stepParams = [])
    {
        $this->createTables();
    }

    /**
     * Gated on 2.2.0's own id rather than the alpha's, so it runs for every install below the
     * release - and guarded with columnExists(), because a step numbered at the release re-runs
     * for anyone who took a pre-release and ADD COLUMN is not idempotent.
     */
    public function upgrade2020070Step1(array $stepParams = [])
    {
        $sm = $this->schemaManager();

        if (!$sm->columnExists('xf_archivesite_user', 'previous_scheme_class'))
        {
            $sm->alterTable('xf_archivesite_user', function(Alter $table)
            {
                $table->addColumn('previous_scheme_class', 'varchar', 100)->setDefault('');
            });
        }
    }

	/**
	 * Remove files that this add-on used to ship and no longer does.
	 *
	 * XenForo's extractor never deletes - ExtractorService::copyFiles() uses the changeset only
	 * as a skip filter - so a file dropped by a new version stays on the user's server for good.
	 * That matters here because 2.0.0 renamed the CLI command classes, and XF\Cli\Runner
	 * discovers commands by scanning Cli/Command rather than from a manifest. An install
	 * upgraded from 1.x therefore still registers that version's `hg:archive-users` and
	 * `hg:restore-users` alongside the current `archive:` commands - verified by installing
	 * the 1.1.1 release on a disposable instance and upgrading onto it.
	 *
	 * Guarded on the XenForo version because the declared floor is 2.2, where neither
	 * AbstractSetup::enqueuePostUpgradeCleanUp() nor XF\Job\FileCleanUp exists. An unguarded
	 * call is a fatal during the upgrade, not a no-op.
	 */
	public function postUpgrade($previousVersion, array &$stateChanges)
	{
		if (\XF::$versionId >= 2030000) // XF 2.3+
		{
			$this->enqueuePostUpgradeCleanUp();
		}
	}

	public function uninstall(array $stepParams = [])
	{
        $this->schemaManager()->dropTable('xf_archivesite_user');
	}

    protected function createTables()
    {
        $this->schemaManager()->createTable('xf_archivesite_user', function(Create $table)
        {
            $table->addColumn('user_id', 'int');
            $table->addColumn('previous_state', 'varchar', 32);
            // the authentication scheme the account had before archiving, so that restoring does
            // not hand a password to an account that never had one. Empty where it is not known:
            // a row written before 2.2.0, or an account that had no authentication row at all.
            $table->addColumn('previous_scheme_class', 'varchar', 100)->setDefault('');
            $table->addPrimaryKey('user_id');
        });
    }
}