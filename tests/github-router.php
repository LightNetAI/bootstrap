<?php
declare(strict_types=1);

/**
 * Fake GitHub API for the repository-listing tests.
 *
 * /user/repos mirrors the authenticated listing (private repos included),
 * /users/{owner}/repos mirrors the public-only endpoint.
 */

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

header('Content-Type: application/json');

$public = [
    'name' => 'public-one',
    'full_name' => 'LightNetAI/public-one',
    'private' => false,
    'owner' => ['login' => 'LightNetAI'],
    'pushed_at' => '2026-01-02T00:00:00Z',
    'default_branch' => 'main',
];

if ($path === '/user/repos') {
    if ($authorization === 'Bearer bad-token') {
        http_response_code(401);
        echo json_encode(['message' => 'Bad credentials']);
        return;
    }

    echo json_encode([
        [
            'name' => 'private-one',
            'full_name' => 'LightNetAI/private-one',
            'private' => true,
            'owner' => ['login' => 'LightNetAI'],
            'pushed_at' => '2026-01-03T00:00:00Z',
            'default_branch' => 'main',
        ],
        $public,
        [
            'name' => 'someone-else',
            'full_name' => 'someone-else/other',
            'private' => true,
            'owner' => ['login' => 'someone-else'],
            'pushed_at' => '2026-01-01T00:00:00Z',
            'default_branch' => 'main',
        ],
    ]);
    return;
}

if (str_starts_with($path, '/users/')) {
    echo json_encode([$public]);
    return;
}

http_response_code(404);
echo json_encode(['message' => 'Not Found']);
