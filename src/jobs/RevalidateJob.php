<?php

namespace ether\utilitybelt\jobs;

use Craft;
use craft\queue\BaseJob;

class RevalidateJob extends BaseJob
{

	public $uris = [];

	protected function defaultDescription (): string
	{
		return 'Revalidating Front-end';
	}

	public function execute ($queue): bool
	{
		// Skip if localhost
		if (str_contains(getenv('FRONTEND_URL'), 'local'))
			return true;

		$this->uris = array_unique($this->uris);

		$client = Craft::createGuzzleClient();
		$url = getenv('FRONTEND_URL') . '/api/revalidate?token=' . getenv('REVALIDATE_TOKEN') . '&path=';
		$total = count($this->uris);
		$i = 0;

		foreach ($this->uris as $uri)
		{
			$client->get($url . $uri);
			$queue->setProgress(++$i / $total * 100, $uri);
		}

		return true;
	}

}