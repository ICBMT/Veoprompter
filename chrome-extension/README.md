# VeoPrompter for Google Flow

This is a Chrome Manifest V3 extension kept separate from the Laravel application code. It receives the current generated result through a site-scoped content-script bridge, stores the active prompt in `chrome.storage.local`, and pastes one part at a time into the Google Flow prompt composer.

## Load it in Chrome

1. Visit `chrome://extensions` and turn on **Developer mode**.
2. Select **Load unpacked** and choose this `chrome-extension/` directory.
3. Open the VeoPrompter application in a tab. Open this extension's popup and select **Connect this site**. Approve the origin-specific access request. The current page is connected immediately; future visits to that origin use the registered bridge.
4. Generate a project prompt and select **Send to Flow** beside Copy all and Save .txt.
5. Open `https://labs.google/fx/tools/flow/`, then open the extension popup. The changing main action first pastes Sections 1–7, then each clip prompt. Send each message in Flow yourself before pasting the next, and keep using the same Flow tab so the conversation retains its context. Sections 9–10 are included with the last clip.

For an extension-mode result or a prompt with a different structure, the extension keeps the complete copy-ready prompt together or falls back to the whole result as one part. It never sends a Flow message or starts video generation on your behalf.

**Back/undo:** the link below the main action moves back one part. If the last extension paste is still unsent in Flow, it clears that matching text first. If it has already been sent, the Flow conversation is left as-is and the part is made available to paste again. “Start this prompt over” rewinds the sequence. Sending a different prompt from VeoPrompter replaces the currently active extension prompt.

## Local storage and permissions

- Prompt text remains on-device in Chrome extension local storage. It is not uploaded to VeoPrompter or another service by the extension.
- The extension only receives app-page messages from a VeoPrompter origin that the user explicitly connected.
- Flow access is limited to the Google Flow hosts declared in `manifest.json`.
- The Flow content script locates a visible text box and refuses to paste over non-empty text. It does not click Flow's Send button.

## Parser tests

From the Laravel repository root, run:

```sh
node --test chrome-extension/tests/prompt-parser.test.js
```
