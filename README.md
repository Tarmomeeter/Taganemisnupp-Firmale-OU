# Taganemisnupp – Firmale OÜ

WooCommerce’i taganemisavalduste WordPressi plugin.

## Paigaldamine

Laadi GitHub Releases lehelt `firmale-taganemisvorm.zip` ja paigalda see WordPressis menüüst **Pluginad → Lisa uus → Laadi plugin üles**.

## Automaatsed uuendused privaatsest repost

Loo GitHubis fine-grained personal access token, millel on ainult selle repositooriumi **Contents: Read-only** õigus. Lisa token WordPressi `wp-config.php` faili enne rida `/* That's all, stop editing! */`:

```php
define('FIRMALE_GITHUB_TOKEN', 'github_pat_...');
```

Seejärel lülita WordPressi **Pluginad** lehel selle plugina automaatsed uuendused sisse. Tokenit ei tohi lisada plugina failidesse ega GitHubi repositooriumisse.

## Uue versiooni avaldamine

1. Uuenda versiooni failides `firmale-taganemisvorm.php` ja `readme.txt`.
2. Commit’i ja push’i muudatused.
3. Loo ning push’i sama versiooniga Git tag, näiteks `v1.2.0-rc.2`.
4. GitHub Actions loob ZIP-faili ja avaldab GitHub Release’i.

WordPress kontrollib GitHubi uusimat Release’i ning pakub uuendust, kui selle versioon on paigaldatud versioonist uuem.
