<?php
namespace Rusgeocom\Rusgeocom\Catalog\Entities;

class FilterPropHint implements \JsonSerializable
{
	private $title = '';
	private $text = '';
	private $videoUrl = '';

	public function __construct(string $title, string $text, string $videoUrl)
	{
		$this->title = $title;
		$this->text = $text;
		$this->videoUrl = $videoUrl;
	}

	public function jsonSerialize(): array
	{
		return [
			'title' => $this->title ?: null,
			'text' => $this->text ?: null,
			'videoUrl' => $this->videoUrl ?: null,
		];
	}
}