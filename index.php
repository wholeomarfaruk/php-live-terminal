```php
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Web Terminal</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="terminal-window">

    <div class="terminal-header">
        <div class="dots">
            <span class="red"></span>
            <span class="yellow"></span>
            <span class="green"></span>
        </div>

        <div class="title">
            Web Terminal
        </div>

        <button id="clearBtn">Clear</button>
    </div>

    <div id="output" class="terminal-output">
        <div class="line">
            <span class="green-text">Server Terminal</span>
        </div>

        <div class="line">
            Type <span class="yellow-text">help</span> to see available commands.
        </div>
    </div>

    <div class="terminal-input-row">
        <span class="prompt" id="prompt">user@server:~$</span>

        <input
            type="text"
            id="commandInput"
            autocomplete="off"
            autofocus
            spellcheck="false"
        >
    </div>

</div>

<script>

const input = document.getElementById("commandInput");
const output = document.getElementById("output");
const prompt = document.getElementById("prompt");
const clearBtn = document.getElementById("clearBtn");

let history = [];
let historyIndex = -1;

function addOutput(command, result) {

    const commandLine = document.createElement("div");

    commandLine.className = "command-line";

    commandLine.innerHTML =
        `<span class="green-text">${escapeHtml(prompt.textContent)}</span>
         <span>${escapeHtml(command)}</span>`;

    output.appendChild(commandLine);

    const resultBox = document.createElement("pre");

    resultBox.className = "result";

    resultBox.textContent = result;

    output.appendChild(resultBox);

    output.scrollTop = output.scrollHeight;
}

function escapeHtml(text) {

    const div = document.createElement("div");

    div.textContent = text;

    return div.innerHTML;
}

async function executeCommand(command) {

    if (!command.trim()) {
        return;
    }

    history.push(command);
    historyIndex = history.length;

    try {

        const response = await fetch("terminal.php", {

            method: "POST",

            headers: {
                "Content-Type": "application/x-www-form-urlencoded"
            },

            body: "command=" + encodeURIComponent(command)

        });

        const data = await response.json();

        addOutput(command, data.output || data.error || "");

        if (data.cwd) {
            prompt.textContent = data.cwd + "$";
        }

    } catch (error) {

        addOutput(command, "Connection error: " + error.message);

    }
}

input.addEventListener("keydown", function(e) {

    if (e.key === "Enter") {

        const command = input.value.trim();

        if (command) {
            executeCommand(command);
        }

        input.value = "";

    }

    if (e.key === "ArrowUp") {

        e.preventDefault();

        if (history.length > 0) {

            historyIndex--;

            if (historyIndex < 0) {
                historyIndex = 0;
            }

            input.value = history[historyIndex];

        }
    }

    if (e.key === "ArrowDown") {

        e.preventDefault();

        if (history.length > 0) {

            historyIndex++;

            if (historyIndex >= history.length) {

                historyIndex = history.length;

                input.value = "";

            } else {

                input.value = history[historyIndex];

            }
        }
    }

});

clearBtn.addEventListener("click", function() {

    output.innerHTML = `
        <div class="line">
            <span class="green-text">Terminal cleared.</span>
        </div>
    `;

    input.focus();

});

document.addEventListener("click", function() {

    input.focus();

});

</script>

</body>
</html>
```
