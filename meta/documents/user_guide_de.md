# S39 Staffelpreise

Rechnet Staffelpreise im Warenkorb über alle Größen (Varianten) eines Artikels zusammen.

Beispiel: Staffel „ab 10 Stück“ – ein Verein bestellt 4× S, 3× M und 5× L desselben Trikots.
Plenty rechnet je Größe (4, 3, 5 → kein Staffelpreis). Mit diesem Plugin zählt die Gesamtmenge (12 → Staffelpreis für alle drei Positionen).

Zusätzlich kopiert das Plugin beim Anlegen eines Auftrags vom Kunden hochgeladene Dateien (Auftragsmerkmale vom Typ „Datei“, z. B. Vereinslogos) in die Auftragsdokumente („Externe Dokumente“). So können sie in E-Mail-Vorlagen unter „Dynamische Anhänge » Externe Dokumente“ mitgesendet werden.

## Einstellungen
- **Staffelpreise über alle Größen zusammenrechnen**: Ja/Nein
- **Hochgeladene Dateien in die Auftragsdokumente kopieren**: Ja/Nein
- **Ausführliches Protokoll**: schreibt jeden Lauf ins Log (Daten » Log, Plugin S39Staffel). Nur zur Fehlersuche einschalten.

## Hinweise
- Es werden die normalen Verkaufspreise/Staffelpreise des Artikels verwendet, inkl. Kundenklasse, Land und Währung.
- Artikelsets werden nicht angefasst.
- Greift keine Staffel (mehr), rechnet Plenty wieder wie gewohnt.
