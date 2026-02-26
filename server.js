const express = require("express");

const app = express();
const port = Number(process.env.PORT || 3000);
const phpAppUrl = (process.env.PHP_APP_URL || "").trim();

app.get("/health", (_req, res) => {
  res.status(200).json({ status: "ok", runtime: "node-wrapper" });
});

if (phpAppUrl.length > 0) {
  app.use((req, res) => {
    // Preserve the original path/query when redirecting to the PHP deployment URL.
    const target = new URL(req.originalUrl || "/", phpAppUrl);
    res.redirect(302, target.toString());
  });
} else {
  app.get("*", (_req, res) => {
    res.status(200).send(`<!doctype html>
<html>
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Al Mudheer Node Wrapper</title>
    <style>
      body { font-family: Arial, sans-serif; margin: 40px; color: #222; }
      code { background: #f3f4f6; padding: 2px 6px; border-radius: 4px; }
      .box { border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; max-width: 760px; }
    </style>
  </head>
  <body>
    <div class="box">
      <h2>Al Mudheer Node Deployment Wrapper</h2>
      <p>This repository contains a PHP application. This Node process is a compatibility wrapper for Hostinger Node deployments.</p>
      <p>Set <code>PHP_APP_URL</code> in your Node environment to enable automatic redirect to your PHP deployment domain.</p>
      <p>Health endpoint: <code>/health</code></p>
    </div>
  </body>
</html>`);
  });
}

app.listen(port, () => {
  console.log(`Node wrapper listening on port ${port}`);
});
