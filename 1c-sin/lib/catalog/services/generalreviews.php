<?php
namespace Rusgeocom\Rusgeocom\Catalog\Services;

use Rusgeocom\Rusgeocom\Catalog\Entities\GeneralReview;
use Rusgeocom\Rusgeocom\Utils\HlBlockHelperRegistry;

class GeneralReviews
{
	const HIGHLOAD_REVIEWS_CODE = 'RusgeocomReviews';

	public static function getAll(): array
	{
		$filter = [
			'!UF_NAME' => false,
			'!UF_COMMENT' => false,
			'!UF_PLACE_OF_WORK' => false,
		];

		$dbReviews = HlBlockHelperRegistry::getInstance()->getByCode(self::HIGHLOAD_REVIEWS_CODE)->getElementsByFilter($filter, ['*'], 10);

		$reviews = [];
		foreach ($dbReviews as $dbReview){
			$reviews[] = new GeneralReview($dbReview);
		}

		return $reviews;
	}
}