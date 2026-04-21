# Channels

Channels are Coqui's first-class external messaging surfaces. They let the API server receive inbound messages from external platforms, route those messages through a normal Coqui session, and send the assistant's reply back out through the same transport.

The first built-in integration is Signal via `signal-cli`.

## Current Scope

The current Signal integration supports:

- inbound Signal messages through a long-lived `signal-cli` JSON-RPC runtime
- one Coqui session per remote conversation
- background-task execution for inbound messages so the API loop stays responsive
- outbound text replies queued in SQLite and flushed by the Signal runtime
- direct-message replies and group replies when the inbound event includes a group ID

The current first pass does not yet support:

- attachment content ingestion
- proactive outbound sends unrelated to an inbound conversation
- Telegram or Discord transport runtimes

## Requirements

- Coqui API mode, not REPL-only mode
- PHP and Coqui already installed
- `signal-cli` installed and runnable on the same machine as the Coqui API server
- Java Runtime Environment 25 or later

The official `signal-cli` docs state that binary releases work on Linux, macOS, and Windows and now require at least JRE 25. Native libraries are bundled for common macOS builds.

## Install `signal-cli`

On macOS, the simplest install path is Homebrew:

```bash
brew install signal-cli
```

The Homebrew formula currently ships bottled builds for both Apple Silicon and Intel macOS releases and pulls in the required `openjdk` dependency automatically.

Verify the install:

```bash
signal-cli --version
java --version
```

If Homebrew is not available, the upstream `signal-cli` README also documents a binary-release install flow. The same release tarballs work on macOS and Linux.

### macOS via Homebrew

This is the recommended path for local macOS testing and development.

### 1. Install Homebrew if needed

If Homebrew is not already installed, follow the standard install flow from [brew.sh](https://brew.sh).

### 2. Install `signal-cli`

```bash
brew install signal-cli
```

### 3. Verify the CLI and Java runtime

```bash
signal-cli --version
java --version
which signal-cli
```

If `java --version` does not report a working Java 25 runtime after Homebrew finishes, fix that before moving on.

### 4. Continue with account registration or linking

Once `signal-cli --version` works, skip down to [Attach a Signal Account](#attach-a-signal-account).

### Manual binary install fallback

### 1. Install Java 25+

Install a JRE 25 distribution for your platform before installing `signal-cli`.

The official quickstart shows examples such as:

```bash
sudo apt install openjdk-25-jre
```

On macOS, install any JRE 25 distribution you normally use, then continue with the binary release install below.

### 2. Download and extract the latest release

```bash
VERSION=$(curl -Ls -o /dev/null -w %{url_effective} https://github.com/AsamK/signal-cli/releases/latest | sed -e 's/^.*\/v//')
curl -L -O https://github.com/AsamK/signal-cli/releases/download/v"${VERSION}"/signal-cli-"${VERSION}".tar.gz
tar xf signal-cli-"${VERSION}".tar.gz
```

### 3. Put `signal-cli` on your `PATH`

One option is to symlink the extracted binary:

```bash
sudo ln -sf "$PWD/signal-cli-${VERSION}/bin/signal-cli" /usr/local/bin/signal-cli
```

Verify the install:

```bash
signal-cli --version
```

## Attach a Signal Account

You have two practical options.

### Option A: Register a dedicated number

This is the most explicit CLI flow and the easiest one to document end-to-end.

Start registration:

```bash
signal-cli -a +15551234567 register
```

If Signal requires a voice fallback or CAPTCHA, the docs show these variants:

```bash
signal-cli -a +15551234567 register --voice
signal-cli -a +15551234567 register --captcha "signalcaptcha://signal-recaptcha-v2.XXXXX"
```

Verify with the code you receive:

```bash
signal-cli -a +15551234567 verify 123-456
```

If the account has a registration-lock PIN:

```bash
signal-cli -a +15551234567 verify 123-456 --pin 54321
```

### Option B: Link `signal-cli` as a secondary device

`signal-cli` also supports device linking:

```bash
signal-cli link -n "Coqui Signal"
```

This outputs a `sgnl://linkdevice?...` URI for linking to an existing Signal account. Use this when you want Coqui to act as an additional device for your existing Signal identity rather than registering a separate number.

## Test `signal-cli` Before Coqui

Do not test Coqui first. Confirm the transport itself works.

### Send a direct message

```bash
signal-cli -a +15551234567 send -m "Coqui Signal transport test" +15557654321
```

### Receive messages as JSON

```bash
signal-cli -a +15551234567 -o json receive
```

### List groups and capture base64 group IDs

For group testing, `signal-cli` exposes `listGroups` through JSON-RPC:

```bash
echo '{"jsonrpc":"2.0","method":"listGroups","id":"groups"}' | signal-cli -a +15551234567 jsonRpc
```

The response contains group objects with base64 `id` fields. Those are the IDs Signal uses for group sends.

### Send a group message manually

```bash
signal-cli -a +15551234567 send -m "Coqui group transport test" -g "GROUP_ID_BASE64"
```

If these commands do not work, fix `signal-cli` first before configuring Coqui.

## Configure Coqui

Add a channel instance to `openclaw.json`:

```json
{
    "channels": {
        "defaults": {
            "unknownUserPolicy": "deny",
            "defaultProfile": "caelum",
            "healthCheckIntervalSeconds": 30
        },
        "instances": {
            "signal-primary": {
                "driver": "signal",
                "enabled": true,
                "displayName": "Signal Primary",
                "defaultProfile": "caelum",
                "settings": {
                    "account": "+15551234567",
                    "binary": "signal-cli",
                    "ignoreAttachments": true,
                    "sendReadReceipts": false,
                    "receiveMode": "on-start"
                },
                "security": {
                    "linkRequired": true
                }
            }
        }
    }
}
```

Important notes:

- `driver` must be `signal`
- `settings.account` must match the account attached to `signal-cli`
- channels only run under `coqui api` or `coqui-launcher`, because the API server owns runtime reconciliation and background execution
- `security.linkRequired` is recommended for the first integration so unknown senders do not automatically open sessions

## Start Coqui In API Mode

Use either:

```bash
coqui api
```

or:

```bash
./bin/coqui-launcher
```

The launcher is useful if you want the REPL and the API together during testing.

## Link Allowed Senders To Profiles

Before testing inbound execution, link the Signal sender to a Coqui profile.

From the REPL:

```text
/channels link signal-primary +15557654321 caelum
```

That allows direct messages from `+15557654321` to run under the `caelum` profile.

You can inspect links with:

```text
/channels links signal-primary
```

## Test The Full Coqui Flow

### 1. Check channel health

From the REPL:

```text
/channels
/channels status signal-primary
/channels health signal-primary
```

The Signal runtime should move out of `invalid_configuration` and toward a running/ready state once `signal-cli` is installed correctly and the configured account is valid.

### 2. Send a Signal message from a linked sender

Send a direct message from the number you linked with `/channels link`.

### 3. Watch the stored channel state

You can inspect the channel surfaces while the message is processed:

```text
/channels status signal-primary
/channels deliveries signal-primary
```

Or via API:

- `GET /api/v1/channels`
- `GET /api/v1/channels/{id}/events`
- `GET /api/v1/channels/{id}/conversations`
- `GET /api/v1/channels/{id}/deliveries`

Expected first-pass flow:

1. Signal runtime receives the inbound message.
2. Coqui stores a `channel_inbound_events` row.
3. The channel execution manager creates or reuses a session for that remote conversation.
4. A background task runs the inbound prompt through Coqui.
5. When the task completes, Coqui queues a delivery.
6. The Signal runtime flushes that queued delivery back out through `signal-cli`.

## Group Testing

For groups, you need the group's base64 ID.

Fetch it with `listGroups`:

```bash
echo '{"jsonrpc":"2.0","method":"listGroups","id":"groups"}' | signal-cli -a +15551234567 jsonRpc
```

Then send a manual group test message:

```bash
signal-cli -a +15551234567 send -m "Manual group test" -g "GROUP_ID_BASE64"
```

When Coqui receives a group message, the current integration uses the inbound group ID as the conversation scope and sends replies back to that same group.

## Troubleshooting

### Channel shows `invalid_configuration`

Check that:

- `settings.account` is present
- `signal-cli` is on your `PATH` or `settings.binary` points to it
- `receiveMode` is `on-start`

### Channel is running but inbound messages are rejected

Check:

- the sender is linked with `/channels link`
- `security.linkRequired` is not blocking unlinked senders
- `allowedScopes` is not filtering out the current group ID

### `signal-cli` works manually but Coqui never sends replies

Check:

- the API server is running, not just the REPL
- the inbound event created a background task
- the background task completed with non-empty `result` text
- `/channels deliveries signal-primary` shows queued or failed deliveries

## Ready-To-Test Checklist

You are ready to test the first Signal channel integration when all of these are true:

- `signal-cli --version` works
- your Signal account is registered or linked
- manual `signal-cli send` works
- manual `signal-cli receive` works
- `openclaw.json` contains a valid Signal channel instance
- `coqui api` or `coqui-launcher` is running
- `/channels status signal-primary` shows a live runtime
- the remote sender has been linked to a profile

At that point, send a real Signal message to the configured account and Coqui should process it end-to-end.
