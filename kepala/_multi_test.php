<?php
header('Content-Type: application/json');
$names = $_FILES['file']['name'] ?? null;
if (is_array($names)) {
    echo json_encode(['count' => count($names), 'names' => $names]);
} else {
    echo json_encode(['count' => $names === null ? 0 : 1, 'names' => [$names]]);
}
