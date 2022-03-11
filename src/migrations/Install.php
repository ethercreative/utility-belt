<?php

namespace ether\utilitybelt\migrations;

use craft\db\Migration;
use craft\db\Table;
use ether\utilitybelt\services\Revalidator;

class Install extends Migration
{

	public function safeUp ()
	{
		// Revalidator
		// ---------------------------------------------------------------------

		$this->createTable(
			Revalidator::$tableName,
			[ 'jobId' => $this->integer(11) ]
		);

		$this->addForeignKey(
			null,
			Revalidator::$tableName,
			['jobId'],
			Table::QUEUE,
			['id'],
			'CASCADE'
		);

		$this->createTable(
			Revalidator::$urisTableName,
			[
				'id' => $this->primaryKey(),
				'uri' => $this->string(),
				'sectionId' => $this->integer(11),
			]
		);

		$this->addForeignKey(
			null,
			Revalidator::$urisTableName,
			['sectionId'],
			Table::SECTIONS,
			['id'],
			'CASCADE'
		);
	}

	public function safeDown (): bool
	{
		// Revalidator
		// ---------------------------------------------------------------------

		$this->dropTableIfExists(Revalidator::$tableName);
		$this->dropTableIfExists(Revalidator::$urisTableName);

		return true;
	}

}