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
            $table->addPrimaryKey('user_id');
        });
    }
}