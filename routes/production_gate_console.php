<?php

// Preserve existing production jobs; no TEST runtime, generic full-repo console replacement or duplicated schedules.
require __DIR__.'/console.php';
require __DIR__.'/gate_console.php';
