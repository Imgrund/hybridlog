import { defineRailway, github, service } from "railway/iac";

// Railway reads this file through `railway config plan` and `railway config
// apply`, not during a deploy: applying it writes the build and deploy
// settings onto the two app services of whichever project the CLI is
// linked to. The hosted instance and the public demo are separate Railway
// projects built from the same repository, so the service names follow the
// project. Only the two app services are declared here; the Postgres
// service (and anything else living in the project) stays untouched.
export const partial = "hybridlog";

export default defineRailway((ctx, project) => {
    const projectName = ctx.projectName;

    if (!projectName) {
        throw new Error("Link a Railway project first: railway link");
    }

    const prefix = projectName === "hybridlog-demo" ? "hybridlog-demo" : "hybridlog";

    const source = github("Imgrund/hybridlog", { branch: "main", checkSuites: false });

    const build = {
        builder: "DOCKERFILE" as const,
        dockerfilePath: "Dockerfile",
        buildEnvironment: "V3" as const,
    };

    // The web service: boot.sh migrates and seeds, then the healthcheck
    // keeps the previous container serving until the new one answers.
    const web = service(prefix, {
        source,
        build,
        deploy: {
            startCommand: "/app/docker/serve.sh",
            preDeployCommand: ["/app/docker/boot.sh"],
            healthcheckPath: "/up",
            healthcheckTimeout: 120,
            restartPolicyType: "ON_FAILURE",
            restartPolicyMaxRetries: 10,
        },
    });

    // The worker: no healthcheck, so ready.sh stands in for one by failing
    // the deploy while the running worker cannot be replaced by one that
    // has no database.
    const worker = service(`${prefix}-worker`, {
        source,
        build,
        deploy: {
            startCommand: "/app/docker/work.sh",
            preDeployCommand: ["/app/docker/ready.sh"],
            restartPolicyType: "ALWAYS",
        },
    });

    return project(projectName, {
        variables: { managed: false },
        resources: [web, worker],
    });
});
