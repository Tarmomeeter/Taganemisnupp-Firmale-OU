=== Firmale OÜ – WooCommerce taganemisvorm ===
Contributors: firmale
Tags: woocommerce, returns, withdrawal, estonia, breakdance
Requires at least: 6.4
Requires PHP: 7.4
Stable tag: 1.2.0-rc.4
License: GPLv2 or later

Tellimuse kontrolli, 14-päevase tähtaja arvutuse ja tootepõhise taganemisavaldusega kliendivorm.

== Description ==

Lisa Breakdance’i lehele shortcode [firmale_tagastusvorm]. Plugin kontrollib WooCommerce’i tellimust numbri ja arveldusmeili põhjal, kuvab tagastatavad tooted ning salvestab taganemisavalduse haldusesse. Administraator saab valitud toodetele luua käsitsi või toetatud makselüüsi kaudu automaatse WooCommerce’i osalise rahatagastuse.

== Installation ==

1. Laadi plugin ZIP-failina WordPressi ja aktiveeri.
2. Ava WooCommerce > Tagastusvormi seaded.
3. Lisa Breakdance’i Shortcode-elemendiga [firmale_tagastusvorm].
4. Testi enne avaldamist testtellimustega.

== Changelog ==

= 1.2.0-rc.4 (testversioon) =
* Lisatud ühe push'iga avaldamine, versioonikontroll ja automaatselt täienev changelog.

= 1.2.0-rc.3 (testversioon) =
* GitHubi uuendused töötavad nüüd avalikust repositooriumist ilma kliendi ligipääsutokenita.

= 1.2.0-rc.2 (testversioon) =
* Uuendatud WordPressi haldusvaade ja seadete kasutusmugavus.
* Lisatud turvaline uuendamine GitHub Releases kaudu.

= 1.2.0-rc.1 (testversioon) =
* PHP 8.3 kontrollid ja eraldatud testid läbitud; päris WordPressi/WooCommerce'i integratsioon vajab staging-testi.
* Lisatud valikuline Cloudflare Turnstile koos serveripoolse Siteverify kontrolliga.
* Lisatud e-posti 6-kohaline kinnituskood enne tellimuse sisu kuvamist.
* Lisatud puudusega kauba eraldi menetlus ja administraatorile privaatsed manused.
* Lisatud kliendi turvaline jälgimisleht, prinditav tagastusleht ning täpsemad menetluse olekud.
* Lisatud tagastusviisid, vedaja allkirjastatud HTTPS-ühendus, tagastuskood, jälgimis- ja pakisildi URL.
* Lisatud tootepõhised reeglid, pikendatud tagastusperioodid ja mitme saadetise kuupäevaväljad.
* Lisatud poekrediidi ja vahetuse soovi salvestamine, olekute e-kirjad ja kliendile saadetav menetlussõnum. Krediidi väljastamine ja vahetustellimus vajavad eraldi integratsiooni.
* Lisatud rahatagastuse kauba-saabumise lukk, püsivam topelt-tagastuse kaitse, eraldi kasutajaõigus ja kahe kinnitaja võimalus.
* Lisatud saatmiskulu režiimid ja tavapärase tarne hüvitise ülempiir.
* Lisatud auditilogi, privaatsuse eksport/osaline kustutamine, lõpetatud avalduste kontaktandmete eemaldamine ja piiratud koondaruanded.
* Tellimusepõhine atomaarne rahaline lukk; ebaselge maksetulemus vajab kontrollitud käsitsi taastamist.
* Käsitsi WooCommerce'i kanne ei märgi raha automaatselt makstuks.
* Turnstile kontrollib serveris kohustuslikku domeeni, tegevust ja eduvastust.

= 1.1.3 =
* Laiendatud globaalsete värvide ja fontide kasutamine parempoolsele sticky-infokastile.

= 1.1.2 =
* Parandatud mobiilivaate horisontaalne ülevool ja väga kitsaste ekraanide tooterea paigutus.

= 1.1.1 =
* Lisatud administraatori valik Elementori või Breakdance’i globaalsete värvide ja fontide kasutamiseks.

= 1.1.0 =
* Lisatud tootepõhine hinnanguline tagastussumma kliendivaates.
* Lisatud osalise WooCommerce’i rahatagastuse tööriist tagastusavaldusele.
* Lisatud käsitsi ja makselüüsi kaudu automaatne tagastusviis, saatmiskulu ning laoseisu valikud.
* Lisatud administraatori seadistused ja topelt-tagastuse kaitse.

= 1.0.2 =
* Parandatud tagastatud koguse lugemine olemasoleva WooCommerce’i tellimuse kontrollimisel.

= 1.0.1 =
* Parandatud vormi käivitumine JavaScripti optimeerimis- ja vahemälulahendustega saitidel.

= 1.0.0 =
* Esimene versioon.
