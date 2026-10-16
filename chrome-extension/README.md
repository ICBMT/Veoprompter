# VeoPrompter for Google Flow

This is a Chrome Manifest V3 extension kept separate from the Laravel application code. It receives the generated result through a site-scoped content-script bridge, stores the active prompt in `chrome.storage.local`, and copies one part at a time to the clipboard. **Google Flow does not need to be open while the extension processes or copies the prompt.**

## Load it in Chrome

1. Visit `chrome://extensions` and turn on **Developer mode**.
2. Select **Load unpacked** and choose this `chrome-extension/` directory.
3. Open the VeoPrompter application in a tab. Open this extension's popup and select **Connect this site**. Approve the origin-specific access request. The current page is connected immediately; future visits to that origin use the registered bridge.
4. Generate a project prompt and select **Send to Flow** beside Copy all and Save .txt. The extension parses the prompt and stores it locally.
5. Open the extension popup on any tab and click its changing main action. It copies Sections 1–7 first, then each clip prompt in order. Paste each clipboard part into Flow whenever you are ready, send it yourself, then copy the next part. Sections 9–10 are included with the final clip.

For an extension-mode result or a prompt with a different structure, the extension keeps the complete copy-ready prompt together or falls back to the whole result as one part. It does not connect to Flow, submit messages, or start video generation.

**Back:** the link below the main action rewinds one part; the main action then copies that part again. **Start this prompt over** resets the sequence to Sections 1–7. Sending a different prompt from VeoPrompter replaces the currently active extension prompt.

## Local storage and permissions

- Prompt text remains on-device in Chrome extension local storage. The extension does not upload it to VeoPrompter, Flow, or another service.
- The extension only receives app-page messages from a VeoPrompter origin that the user explicitly connected.
- Clipboard write access is used only when the user clicks a Copy action. Flow's website permissions are not requested.

## Parser tests

From the Laravel repository root, run:

```sh
node --test chrome-extension/tests/prompt-parser.test.js
```
