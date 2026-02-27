import { PrismaClient } from "@prisma/client";

const prisma = new PrismaClient();

async function main() {
  const baseSettings: Array<{ key: string; value: string }> = [
    { key: "companysettings.sitename", value: "Al Mudheer" },
    { key: "companysettings.microsoftAuth.enabled", value: "false" },
    { key: "companysettings.microsoftAuth.allowPublicRegistration", value: "false" },
    { key: "companysettings.microsoftAuth.defaultRole", value: "20" },
    { key: "auth.hideDefaultLogin", value: "off" }
  ];

  for (const item of baseSettings) {
    await prisma.setting.upsert({
      where: { key: item.key },
      update: { value: item.value },
      create: item
    });
  }
}

main()
  .catch((e) => {
    console.error(e);
    process.exit(1);
  })
  .finally(async () => {
    await prisma.$disconnect();
  });
