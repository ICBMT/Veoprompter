# VeoPrompter for Google Flow

This is a Chrome Manifest V3 extension kept separate from the Laravel application code. It receives generated prompts through a site-scoped VeoPrompter bridge, stores the active prompt in `chrome.storage.local`, and prepares Sections 1–7 followed by each clip prompt. Sections 9–10 stay attached to the final clip; unrecognized formats remain a single copy-ready part.

## Load it in Chrome

1. Visit `chrome://extensions`, turn on **Developer mode**, and select **Load unpacked**. Choose this `chrome-extension/` directory. If it is already installed unpacked, click **Reload** to apply the update.
2. Open the VeoPrompter application in a tab. Open the extension popup and select **Connect this site**. Approve the origin-specific access request; the current page is connected immediately.
3. Generate a project prompt and select **Send to Flow** beside Copy all and Save .txt. The extension parses the prompt and stores it locally.
4. For automatic prompt-box filling, open Google Flow, then open the extension popup while the Flow tab is active. Click **Fill Flow** for Sections 1–7. The extension places the text in Flow’s empty prompt box and also attempts to copy it to the clipboard as a backup. Review the prompt and click Flow’s **Send** button yourself. After it is sent, reopen the extension and fill the next clip. Sections 9–10 are included with the final clip.
5. If Flow is not the active tab, the main action copies the next part to the clipboard instead; Flow can stay closed while you prepare the sequence. On a Flow tab, **Copy this part to clipboard instead** is available as a fallback.

The extension never clicks Flow’s Send button or starts video generation. It does not overwrite text already in Flow’s composer. If the composer is occupied or cannot be found, the extension explains the issue and leaves the sequence at the current part so you can retry or use the clipboard fallback.

**Back:** rewinds one part. If the Flow composer still contains exactly the last unsent text inserted by the extension, it clears that text; changed text is left untouched, and sent chat messages are never removed. **Start this prompt over** resets the sequence to Sections 1–7. Sending a different prompt from VeoPrompter replaces the active extension prompt.

## Local storage and permissions

- Prompt text remains on-device in Chrome extension local storage. The extension does not upload it to VeoPrompter, Flow, or another service.
- The extension only receives app-page messages from a VeoPrompter origin that the user explicitly connected.
- No persistent Google Flow host permission is requested. When Flow is the active tab and you click **Fill Flow**, the extension uses Chrome’s temporary `activeTab` access to fill the Flow composer. It does not submit the prompt.
- Clipboard write access is used when you click a Copy action or fill a part in Flow. The clipboard copy provides a manual-paste fallback.

## Tests

From the Laravel repository root, run:

```sh
node --test chrome-extension/tests/*.test.js
```
