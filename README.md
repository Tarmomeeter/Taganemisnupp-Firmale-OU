# Taganemisnupp – Firmale OÜ

WooCommerce’i taganemisavalduste WordPressi plugin.

## Paigaldamine

Laadi GitHub Releases lehelt `firmale-taganemisvorm.zip` ja paigalda see WordPressis menüüst **Pluginad → Lisa uus → Laadi plugin üles**.

## Automaatsed uuendused

Plugin kontrollib avaliku GitHubi repositooriumi uusimat Release’i WordPressi tavapärase uuendussüsteemi kaudu. Ligipääsutokenit ei ole vaja. Soovi korral lülita WordPressi **Pluginad** lehel selle plugina automaatsed uuendused sisse.

## Uue versiooni avaldamine

1. Uuenda versiooni failides `firmale-taganemisvorm.php` ja `readme.txt`.
2. Commit’i ja push’i muudatused.
3. Loo ning push’i sama versiooniga Git tag, näiteks `v1.2.0-rc.3`.
4. GitHub Actions loob ZIP-faili ja avaldab GitHub Release’i.

WordPress kontrollib GitHubi uusimat Release’i ning pakub uuendust, kui selle versioon on paigaldatud versioonist uuem.
