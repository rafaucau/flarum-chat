#!/usr/bin/env node
/**
 * Reporting a message, driven in a headless Edge as a member.
 *
 *   - with flarum/flags enabled, the report button opens that extension's own
 *     dialog (its reasons and wording), not the chat's
 *   - "other" without an explanation is refused inside the dialog
 *   - a reason it offers files the report in the chat's queue, and the dialog
 *     shows flarum/flags' confirmation
 *   - the message's button reads "reported" afterwards
 *   - a moderator with the channel open sees the message marked as reported
 *     without reloading (flarum/realtime), the reporter does not, and the mark
 *     clears once the report is resolved
 *
 * Requires tests/E2E/.tokens.json (php tests/E2E/mint-tokens.php) and Edge.
 */
import {
  api,
  createChannel,
  deleteChannel,
  joinChannel,
  sendMessage,
} from "./lib/api.mjs";
import { harness, loadTokens, log, sleep } from "./lib/env.mjs";
import { launchBrowser } from "./lib/browser.mjs";

const t = harness("flag-modal");
const { admin, a } = await loadTokens();
const stamp = Date.now().toString(36);

const created = await createChannel(admin.token, {
  name: "E2E report " + stamp,
  description: "created by tests/E2E/flag-modal.mjs",
  isPrivate: false,
});

await t.must("admin creates a channel", created.status === 201, "HTTP " + created.status);

const channelId = Number(created.json.data.id);

const joined = await joinChannel(a.token, channelId);
await t.must("A joins it", joined.status === 200, "HTTP " + joined.status);

const sent = await sendMessage(admin.token, channelId, "Something to report " + stamp);
await t.must("admin posts a message", sent.status === 201, "HTTP " + sent.status);

const messageId = Number(sent.json.data.id);
const inMessage = (selector) =>
  "document.querySelector('.ChatMessage[data-id=\"" + messageId + "\"] " + selector + "'.trim())";
const flagButton = inMessage(".ChatMessage-action .fa-flag") + "?.closest('button')";
const modal = ".FlagPostModal";

const browser = await launchBrowser({ label: "reporter" });
const moderator = await launchBrowser({ label: "moderator" });
const flagMark = inMessage(".ChatMessage-flagMark");

try {
  await moderator.loginWithRememberToken(admin.remember);
  await moderator.goto("/chat/c/" + channelId);
  await t.must(
    "the moderator has the channel open",
    Boolean(await moderator.waitFor(inMessage("") + " !== null", 20000)),
  );
  await t.check("the message starts unmarked", (await moderator.evaluate(flagMark + " === null")) === true);

  await browser.loginWithRememberToken(a.remember);
  await browser.goto("/chat/c/" + channelId);

  await t.must(
    "the message is drawn with a report button",
    Boolean(await browser.waitFor(flagButton + " !== null", 20000)),
  );

  const flagsEnabled = await browser.evaluate("'flarum-flags' in (flarum.extensions ?? {})");
  await t.must("flarum/flags is enabled on this forum", flagsEnabled === true, String(flagsEnabled));

  await browser.clickWhere(flagButton);
  await t.must(
    "the report button opens flarum/flags' dialog",
    Boolean(await browser.waitFor("document.querySelector('" + modal + "') !== null", 15000)),
  );

  const chatDialog = await browser.evaluate("document.querySelector('.ChatFlagModal') !== null");
  await t.check("the chat's own dialog is not opened as well", chatDialog === false);

  const reasons = await browser.evaluate(
    "[...document.querySelectorAll('" + modal + " input[name=reason]')].map(i => i.value)",
  );
  await t.check(
    "it offers flarum/flags' reasons",
    JSON.stringify(reasons) === JSON.stringify(["off_topic", "inappropriate", "spam", "other"]),
    JSON.stringify(reasons),
  );

  const title = await browser.evaluate("document.querySelector('" + modal + " .Modal-title')?.textContent.trim()");
  await t.check("it is titled as a message report", Boolean(title), JSON.stringify(title));
  await browser.screenshot("flag-01-dialog");

  await browser.click(modal + " input[value=other]");
  await browser.waitFor("document.querySelector('" + modal + " textarea') !== null", 5000);
  await browser.click(modal + " button[type=submit]");

  const refused = await browser.waitFor(
    "document.querySelector('" + modal + " .Alert') !== null && document.querySelector('" + modal + " .helpText') === null",
    10000,
  );
  await t.check("\"other\" without an explanation is refused in the dialog", Boolean(refused));
  await browser.screenshot("flag-02-refused");

  await browser.click(modal + " input[value=spam]");
  await browser.click(modal + " button[type=submit]");

  await t.check(
    "a listed reason is accepted and the confirmation shows",
    Boolean(await browser.waitFor("document.querySelector('" + modal + " .helpText') !== null", 10000)),
  );
  await browser.screenshot("flag-03-confirmed");

  const queue = await api(admin.token, "GET", "/chat-message-flags?filter[resolved]=0&page[limit]=50");
  const filed = (queue.json?.data ?? []).find(
    (row) =>
      Number(row.relationships?.message?.data?.id) === messageId &&
      Number(row.relationships?.user?.data?.id) === Number(a.id),
  );
  await t.check("the report is in the chat's queue", Boolean(filed), "HTTP " + queue.status);
  await t.check("with the reason picked", filed?.attributes?.reason === "spam", JSON.stringify(filed?.attributes));

  const live = await moderator.waitFor(flagMark + "?.textContent.trim()", 15000);
  await t.check("the moderator sees it marked without reloading", Boolean(live), JSON.stringify(live));
  await t.check(
    "the row carries the reported bar",
    (await moderator.evaluate("document.querySelector('.ChatMessage[data-id=\"" + messageId + "\"]').classList.contains('ChatMessage--flagged')")) === true,
  );
  await moderator.screenshot("flag-04-moderator-marked");

  await t.check("the reporter's view has no mark", (await browser.evaluate(flagMark + " === null")) === true);

  if (filed) {
    const resolved = await api(admin.token, "POST", "/chat-message-flags/" + filed.id + "/resolve");
    await t.check("the moderator resolves it", resolved.status === 200, "HTTP " + resolved.status);

    const cleared = await moderator.waitFor(flagMark + " === null", 15000);
    await t.check("the mark clears once resolved", Boolean(cleared));
  }

  await browser.clickWhere("document.querySelector('" + modal + " .helpText')?.closest('form')?.querySelector('button')");
  await browser.waitFor("document.querySelector('" + modal + "') === null", 5000);

  const active = await browser.waitFor(flagButton + "?.classList.contains('ChatMessage-action--active')", 5000);
  await t.check("the message's button reads as reported", Boolean(active));

  const errors = [...browser.consoleErrors, ...moderator.consoleErrors].filter((e) => !/favicon|manifest/i.test(e));
  await t.check("no uncaught page exceptions", errors.length === 0, errors.slice(0, 3).join(" | "));
} catch (e) {
  await t.fail("unexpected error", String(e?.stack ?? e));
  try {
    await browser.screenshot("flag-99-failure");
  } catch {
    // Nothing more to capture.
  }
} finally {
  await browser.close();
  await moderator.close();

  const removed = await deleteChannel(admin.token, channelId);
  log("cleanup channel " + channelId + " HTTP " + removed.status);
}

await sleep(100);
process.exit(t.summary());
