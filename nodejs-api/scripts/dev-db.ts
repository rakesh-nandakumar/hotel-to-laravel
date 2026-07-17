/**
 * Local development database — embedded PostgreSQL on port 15432
 * (5432/5433 are commonly blocked on Windows by AV/Hyper-V reservations).
 * Use when Docker isn't available on the dev machine:
 *   npm run db:dev        (keep running in a terminal)
 * Data persists in apps/api/.pgdata.
 */
import EmbeddedPostgres from "embedded-postgres";
import path from "path";

const pg = new EmbeddedPostgres({
  databaseDir: path.join(__dirname, "..", ".pgdata"),
  user: "mountview",
  password: "mountview",
  port: 15432,
  persistent: true,
  // UTF-8 cluster — Windows initdb otherwise defaults to WIN1252, which
  // cannot store the app's unicode content
  initdbFlags: ["--encoding=UTF8", "--locale=C"],
});

async function main() {
  const fresh = !require("fs").existsSync(path.join(__dirname, "..", ".pgdata", "PG_VERSION"));
  if (fresh) {
    console.log("Initialising embedded PostgreSQL cluster…");
    await pg.initialise();
  }
  await pg.start();
  if (fresh) await pg.createDatabase("mountview");
  console.log("PostgreSQL ready on postgresql://mountview:mountview@localhost:15432/mountview");
  console.log("Press Ctrl+C to stop.");
  const stop = async () => {
    await pg.stop();
    process.exit(0);
  };
  process.on("SIGINT", stop);
  process.on("SIGTERM", stop);
}

main().catch(async (e) => {
  console.error(e);
  try {
    await pg.stop();
  } catch {}
  process.exit(1);
});
