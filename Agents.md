# Agenten-Anweisungen: IPS Voice Agent Projekt

Diese Datei enthält die grundlegenden Regeln und Anweisungen, damit wir konsistent an diesem IP-Symcon Projekt arbeiten.

## 1. Kommunikation & Dokumentation
- **Sprache:** Deutsch in der Kommunikation mit dem User. Die Dokumentation im Code (PHPDoc) auf Englisch (oder nach Absprache Deutsch).
- **Git & Commits:** Alle substanziellen Änderungen müssen klar dokumentiert und auf GitHub aktualisiert/committet werden.

## 2. IP-Symcon Modul Architektur
- **Pattern:** Wir nutzen strikt das Parent-Child-Pattern (Splitter/Device bzw. Gateway/Device).
- **Gateway (Parent):** Dient zwingend nur dem I/O (API-Routing) und Secrets Management. Keine spezifische Logiklagerung auf Charakter-Ebene.
- **Device (Child):** Hat die volle Hoheit über den Zustand (Properties), die charakterbezogene Logik (`Speak`) und das Dateisystem-Caching.
- **Namenskonventionen:** Bitte einen einheitlichen Modul-Präfix verwenden (sofern definiert).

## 3. Security (Secrets Management)
- **Niemals API-Keys (OpenAI, ElevenLabs, etc.) in den Code hochladen!**
- API-Schlüssel werden in IP-Symcon ausschließlich in den Instanzeinstellungen (`form.json`) mithilfe vom Control `PasswordTextBox` hinterlegt.

## 4. Fortschrittstracking (Planung & Ausführung)
- Nach größeren Änderungen am Projektkonzept pflegen wir dieses `Agents.md` und das `implementation_plan.md` nach.
- Wir bearbeiten Checklisten synchron mit dem `task.md`.

## 5. Caching Strategie
- Priorisierung von lokal abgelegten Speech-Dateien (Kosteneinsparung / Latenzen).
- Robuster Fallback: Sind APIs (ElevenLabs/LLM) bei der Cache-Auffüllung down, nutze weiterhin die lokalen MP3s, schreibe bei totalem Leerstand einen Fehler per `IPS_LogMessage`.
