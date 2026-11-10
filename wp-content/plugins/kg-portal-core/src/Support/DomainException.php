<?php

defined('ABSPATH') || exit;

final class KGP_Domain_Exception extends RuntimeException {
    private string $slug;

    public function __construct(string $slug, string $message) {
        parent::__construct($message);
        $this->slug = $slug;
    }

    public function slug(): string {
        return $this->slug;
    }
}
