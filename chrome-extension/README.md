# VeoPrompter for Google Flow

This is a Chrome Manifest V3 extension kept separate from the Laravel application code. It receives generated or saved prompts through a site-scoped VeoPrompter bridge and stores the active prompt in `chrome.storage.local`. It prepares Sections 1–7 first, then every numbered clip prompt in order, with continuity notes and final audits attached to the last clip. It supports both the app's standard Section 8 clip list and the newer section-per-clip output layout. Incoming Type A/B/C metadata is reflected in part labels with concise guidance for the next opening. The parser checks each prior `OUTGOING HANDOFF TYPE:` against the next `TRANSITION TYPE:` and flags mismatches, missing incoming labels, and an absent final marker; Type C is identified as an intentional, non-seamless transition. When a prompt contains `VISIBLE CHARACTER CAST:` metadata, the popup surfaces the exact cast for the next part as a cast-lock badge. The complete character identity lock and dialogue voice profiles remain inside each copied standalone clip prompt and are passed through unchanged; the extension does not invent, replace, or reinterpret character identities or voice samples. AI-selected counts and durations need no extension-side settings; the extension follows the prompts actually returned. Unrecognized formats remain a single copy-ready part.

## Load it in Chrome

1. Visit `chrome://extensions`, turn on **Developer mode**, and select **Load unpacked**. Choose this `chrome-extension/` directory. If it is already installed unpacked, click **Reload** to apply the update.
2. Open VeoPrompter in a tab. Open the extension popup and select **Connect this site**. Approve the origin-specific access request; the current page is connected immediately.
3. Generate a prompt and select **Send to Flow** beside Copy all and Save .txt, or open a saved prompt in the prompt library and select its **Send to Flow** button. The extension parses and stores the prompt locally.
4. To paste a part on any site, open the destination tab, open the extension popup, review the cast-lock badge and incoming-handoff guidance for the next part when available, and click **Copy**. The extension copies the full prompt unchanged and arms a one-time listener on that tab. Click a text input, textarea, or editable text field to insert the part at the cursor. Review the copied identity lock in Google Flow, then click Flow’s **Send** button yourself. Repeat for each clip; Sections 9–10 are included with the final clip.
5. If automatic insertion is unavailable or you prefer, use the copied text and your browser’s normal **Paste** command. You can also prepare the sequence with Flow closed; the extension does not require Flow to be open.

The extension never submits a prompt, clicks Flow’s Send button, or starts video generation. It does not overwrite text already in an editable field; it inserts at the current cursor/selection. Automatic paste is armed only after you open the popup on the active destination tab and click Copy. The listener handles one field click, then removes itself. It does not request persistent access to all destination sites.

**Go back one part** and **Start this prompt over** update the stored sequence and cancel any pending one-time paste. Text already inserted into a page is left unchanged. Sending a different prompt from VeoPrompter replaces the active extension prompt.

## Local storage and permissions

- Prompt text and progress remain on-device in Chrome extension local storage. The extension does not upload them to VeoPrompter, Flow, or another service.
- The extension only receives app-page messages from a VeoPrompter origin that the user explicitly connected.
- No persistent destination-site permission is requested. For one-time insertion, the extension uses Chrome’s temporary `activeTab` access after the user opens the popup on that tab and clicks Copy. The app-site connection is separate and scoped to the origin the user approves.
- Clipboard write access is used when you click Copy; normal clipboard paste remains available as a fallback.

## Tests

From the Laravel repository root, run:

```sh
node --test chrome-extension/tests/*.test.js
```
