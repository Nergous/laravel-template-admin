from config.config import Config

def test_config_reads_laravel_db_env(monkeypatch):
    monkeypatch.setenv("API_TOKEN", "t")
    monkeypatch.setenv("BOT_ID", "1")
    monkeypatch.setenv("DB_DATABASE", "mydb")
    monkeypatch.setenv("DB_USERNAME", "myuser")
    monkeypatch.setenv("DB_PASSWORD", "")
    monkeypatch.delenv("DB_HOST", raising=False)
    monkeypatch.delenv("DB_PORT", raising=False)

    cfg = Config.from_env()

    assert cfg.db.database == "mydb"
    assert cfg.db.user == "myuser"
    assert cfg.db.password == ""
    assert cfg.db.host == "127.0.0.1"
    assert cfg.db.port == 3306