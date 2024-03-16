<?php

namespace App;

interface Constants
{
    public const DRY_RUN_MODE_DISABLED = 0;
    public const DRY_RUN_MODE_SUCCESS = 1;
    public const DRY_RUN_MODE_IMMEDIATE_FAILURE = 2;
    public const DRY_RUN_MODES = [
        self::DRY_RUN_MODE_DISABLED,
        self::DRY_RUN_MODE_SUCCESS,
        self::DRY_RUN_MODE_IMMEDIATE_FAILURE
    ];
    public const PROGRESS_MODE_DISABLED = 0;
    public const PROGRESS_MODE_SIMPLE = 1;
    public const PROGRESS_MODE_TWO_PASS = 2;
    public const PROGRESS_MODES = [
        self::PROGRESS_MODE_DISABLED,
        self::PROGRESS_MODE_SIMPLE,
        self::PROGRESS_MODE_TWO_PASS
    ];

    public const WEBSOCKET_TCP_CONNECT_TIMEOUT = 3;
    public const WEBSOCKET_TLS_CONNECT_TIMEOUT = 3;
    public const WEBSOCKET_CONNECT_RETRY = 0;

    public const ES_DOC_VERSION = 3;
}
