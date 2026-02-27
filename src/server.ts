import cors from "cors";
import express from "express";
import session from "express-session";
import helmet from "helmet";
import { env } from "./config.js";
import { localLogin, logout, oidcCallback, requireAuth, ssoOnly, startOidc } from "./auth.js";
import { getDecryptedSetting, getSetting, saveEncryptedSetting, saveSetting, toBool } from "./settings.js";

const app = express();

app.use(helmet());
app.use(cors());
app.use(express.json());
app.use(express.urlencoded({ extended: true }));
app.use(
  session({
    secret: env.SESSION_SECRET,
    resave: false,
    saveUninitialized: false,
    cookie: {
      httpOnly: true,
      sameSite: "lax",
      secure: env.NODE_ENV === "production"
    }
  })
);

app.get("/health", (_req, res) => {
  res.json({ ok: true });
});

app.get("/login", async (req, res) => {
  const fromDb = await getSetting("companysettings.microsoftAuth.enabled", String(env.SSO_ENABLED));
  const oidcEnabled = toBool(fromDb);
  const advanced = ["1", "true", "on", "yes"].includes(String(req.query.advanced ?? "").toLowerCase());
  const noLoginForm = ssoOnly(oidcEnabled, advanced);

  if (noLoginForm) {
    return res.send(
      "<h1>Login</h1><p>SSO only mode is enabled.</p><a href='/auth/oidc/start'>Continue with Microsoft</a><p>Use ?advanced=1 to show local login.</p>"
    );
  }

  return res.send(
    "<h1>Login</h1><form method='post' action='/auth/login'><input name='email' type='email' placeholder='Email'/><button type='submit'>Sign in</button></form><p><a href='/auth/oidc/start'>Continue with Microsoft</a></p>"
  );
});

app.post("/auth/login", localLogin);
app.get("/auth/logout", logout);
app.get("/auth/oidc/start", startOidc);
app.get(env.OIDC_REDIRECT_PATH, oidcCallback);

app.get("/dashboard", requireAuth, async (req, res) => {
  const companyName = await getSetting("companysettings.sitename", "Al Mudheer");
  res.send(`<h1>${companyName}</h1><p>User session: ${req.session.userId}</p><a href='/auth/logout'>Logout</a>`);
});

app.get("/settings/company", requireAuth, async (_req, res) => {
  const payload = {
    siteName: await getSetting("companysettings.sitename", "Al Mudheer"),
    microsoftAuth: {
      enabled: toBool(await getSetting("companysettings.microsoftAuth.enabled", "false")),
      issuer: await getDecryptedSetting("companysettings.microsoftAuth.issuer", ""),
      clientId: await getDecryptedSetting("companysettings.microsoftAuth.clientId", ""),
      hasClientSecret: Boolean(await getDecryptedSetting("companysettings.microsoftAuth.clientSecret", "")),
      allowPublicRegistration: toBool(
        await getSetting("companysettings.microsoftAuth.allowPublicRegistration", "false")
      ),
      defaultRole: Number(await getSetting("companysettings.microsoftAuth.defaultRole", "20"))
    }
  };
  res.json(payload);
});

app.post("/settings/company", requireAuth, async (req, res) => {
  const body = req.body as Record<string, string | undefined>;

  await saveSetting("companysettings.sitename", body.name?.trim() || "Al Mudheer");
  await saveSetting("companysettings.microsoftAuth.enabled", body.microsoftAuthEnabled ? "true" : "false");
  await saveSetting(
    "companysettings.microsoftAuth.allowPublicRegistration",
    body.microsoftAuthAllowPublicRegistration ? "true" : "false"
  );
  await saveSetting("companysettings.microsoftAuth.defaultRole", body.microsoftAuthDefaultRole || "20");
  await saveEncryptedSetting("companysettings.microsoftAuth.issuer", body.microsoftAuthIssuer?.trim() || "");
  await saveEncryptedSetting("companysettings.microsoftAuth.clientId", body.microsoftAuthClientId?.trim() || "");
  if (body.microsoftAuthClientSecret && body.microsoftAuthClientSecret.trim() !== "") {
    await saveEncryptedSetting("companysettings.microsoftAuth.clientSecret", body.microsoftAuthClientSecret.trim());
  }

  res.json({ ok: true });
});

app.listen(env.PORT, () => {
  console.log(`Server running at ${env.APP_URL} on port ${env.PORT}`);
});
