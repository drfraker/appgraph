<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Support\SchemaDumpParser;
use AppGraph\Tests\TestCase;

class SchemaDumpParserTest extends TestCase
{
    public function test_it_parses_mysql_schema_dump_tables_columns_indexes_and_foreign_keys(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `progress_notes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Note title',
  `body` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `progress_notes_title_index` (`title`),
  CONSTRAINT `progress_notes_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        $tables = (new SchemaDumpParser())->parse($sql, 'mysql');
        $progressNotes = collect($tables)->firstWhere('name', 'progress_notes');

        $this->assertNotNull($progressNotes);
        $this->assertSame('title', $progressNotes['columns'][2]['name']);
        $this->assertSame('varchar(255)', $progressNotes['columns'][2]['type']);
        $this->assertSame('Note title', $progressNotes['columns'][2]['comment']);
        $this->assertSame('progress_notes_title_index', $progressNotes['indexes'][1]['name']);
        $this->assertSame(['title'], $progressNotes['indexes'][1]['columns']);
        $this->assertSame('progress_notes_user_id_foreign', $progressNotes['foreignKeys'][0]['name']);
        $this->assertSame(['user_id'], $progressNotes['foreignKeys'][0]['columns']);
        $this->assertSame('users', $progressNotes['foreignKeys'][0]['foreignTable']);
        $this->assertSame(['id'], $progressNotes['foreignKeys'][0]['foreignColumns']);
        $this->assertSame('CASCADE', $progressNotes['foreignKeys'][0]['onDelete']);
    }
}
