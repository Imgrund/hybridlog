<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * The MCP registry accepts a container-shipped server only once the image
 * itself names the server it claims to be, and it compares that name to
 * `server.json` character for character. The name is written in four
 * places, and nothing at build time notices when they drift: the image is
 * built, the tags are pushed, every check is green, and the refusal
 * arrives at `mcp-publisher publish`, by which point the label is baked
 * into a published image and only a new release can correct it.
 *
 * That happened once, over a capital letter. The namespace follows the
 * GitHub account, so it is `io.github.Imgrund`, not `io.github.imgrund`.
 */
class ServerManifestTest extends TestCase
{
    private function manifest(): array
    {
        return json_decode((string) file_get_contents(base_path('server.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_every_place_that_names_the_server_names_it_the_same(): void
    {
        $name = $this->manifest()['name'];

        // The Dockerfile writes the label into each platform's own image
        // config, which is where the registry reads it for a single-arch
        // pull.
        $this->assertStringContainsString(
            'LABEL io.modelcontextprotocol.server.name="'.$name.'"',
            (string) file_get_contents(base_path('Dockerfile')),
            'The Dockerfile label and server.json name have drifted apart.'
        );

        // The release workflow repeats it so the multi-arch index carries
        // it too. What it sets wins over the Dockerfile, so a mismatch
        // here beats a correct Dockerfile rather than the other way round.
        $workflow = (string) file_get_contents(base_path('.github/workflows/release.yml'));
        $occurrences = preg_match_all('/io\.modelcontextprotocol\.server\.name=(\S+)/', $workflow, $matches);

        $this->assertSame(2, $occurrences, 'The workflow should set the name once as a label and once as an annotation.');
        $this->assertSame([$name, $name], $matches[1], 'The release workflow overrides the Dockerfile label with a different name.');
    }

    public function test_the_published_image_is_the_one_this_version_names(): void
    {
        $manifest = $this->manifest();
        $identifier = $manifest['packages'][0]['identifier'];

        $this->assertStringEndsWith(
            ':'.$manifest['version'],
            $identifier,
            'server.json points at an image tag other than its own version, so the registry entry would describe a different build.'
        );
    }

    public function test_the_namespace_matches_the_repository_owner(): void
    {
        // GitHub authentication only grants `io.github.<account>/*`, and
        // the account is the one the repository URL carries.
        $manifest = $this->manifest();
        preg_match('#github\.com/([^/]+)/#', $manifest['repository']['url'], $owner);

        $this->assertSame('io.github.'.$owner[1].'/', substr($manifest['name'], 0, strlen($owner[1]) + 11));
    }
}
