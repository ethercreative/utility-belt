<?php

namespace ether\utilitybelt\jobs;

use Craft;
use craft\elements\Asset;
use craft\queue\BaseJob;

class RevalidateAssetJob extends BaseJob
{

	public $assetIds = [];

	protected function defaultDescription(): ?string
	{
		return 'Revalidating assets';
	}

	public function execute($queue): void
	{
		$doKey = getenv('DO_API_KEY');

		if (empty($doKey)) return;

		$client = Craft::createGuzzleClient();
		$assets = Asset::find()->id($this->assetIds)->all();
		$total = count($this->assetIds);
		$i = 0;

		$headers = [
			'Content-Type' => 'application/json',
			'Authorization' => "Bearer $doKey",
		];

		$response = $client
			->get('https://api.digitalocean.com/v2/cdn/endpoints?per_page=200', ['headers' => $headers])
			->getBody()
			->getContents();

		$allSpaces = json_decode($response)->endpoints;

		foreach ($assets as $asset)
		{
			$volume = $asset->volume;

			$spaces = array_values(array_filter($allSpaces, function ($space) use ($volume) {
				return $space->endpoint === str_replace('https://', '', $volume->getRootUrl());
			}));

			if (!empty($spaces)) {
				$id = $spaces[0]->id;
				$folder = $volume->fs->subfolder;

				$client->request('DELETE', "https://api.digitalocean.com/v2/cdn/endpoints/$id/cache", [
					'headers' => $headers,
					'json' => ['files' => ["$folder/$asset->filename", "$folder/*/$asset->filename"]]
				]);
			}

			$queue->setProgress(++$i / $total * 100, $asset->filename);
		}
	}

}
