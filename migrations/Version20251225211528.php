<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251225211528 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql("CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    email VARCHAR(180) UNIQUE NOT NULL,
    roles JSONB NOT NULL,
    password VARCHAR(255) NOT NULL,
    api_token VARCHAR(64) UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL
);");

        $this->addSql("
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ language 'plpgsql';");

        $this->addSql('CREATE TABLE short_urls (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NULL,
    long_url VARCHAR(2048) NOT NULL,
    short_code VARCHAR(64) UNIQUE NOT NULL,
    custom_alias VARCHAR(64) UNIQUE NULL,
    clicks INTEGER DEFAULT 0 NOT NULL,
    qr_code_path VARCHAR(255) NULL,
    expires_at TIMESTAMP NULL,
    is_active BOOLEAN DEFAULT TRUE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);
');


        $this->addSql('CREATE INDEX idx_short_code ON short_urls (short_code);');
        $this->addSql('CREATE INDEX idx_user_id ON short_urls (user_id, created_at);');
        $this->addSql('CREATE TRIGGER update_short_urls_updated_at
    BEFORE UPDATE ON short_urls
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();');

        $this->addSql('CREATE TABLE link_clicks (
    id SERIAL PRIMARY KEY,
    short_url_id INTEGER NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    referer VARCHAR(1024) NULL,
    country VARCHAR(2) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
    FOREIGN KEY (short_url_id) REFERENCES short_urls(id) ON DELETE CASCADE
);');


        $this->addSql('CREATE INDEX idx_short_url_id ON link_clicks (short_url_id);');
        $this->addSql('CREATE INDEX idx_created_at ON link_clicks (created_at);');
        $this->addSql('
CREATE TRIGGER update_users_updated_at
    BEFORE UPDATE ON users
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();');


    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs

    }
}
