<?php
// Configuración del Bot
$token = "8517740193:AAHlg6MAOW5VCeHXdpC4PSicmgz1hcEJK64";

// Lista de IDs de Telegram de los administradores autorizados
$admins_permitidos = [6356260726, 8097130283]; 

$apiUrl = "https://api.telegram.org/bot$token/";

// Leer el JSON enviado por Telegram
$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) {
    exit;
}

// Función auxiliar para realizar peticiones a la API de Telegram
function telegramApi($method, $data) {
    global $apiUrl;
    $options = [
        'http' => [
            'header'  => "Content-type: application/json\r\n",
            'method'  => 'POST',
            'content' => json_encode($data),
        ],
    ];
    $context = stream_context_create($options);
    @file_get_contents($apiUrl . $method, false, $context);
}

// Cargar catálogo de videos de forma segura
function cargarVideos() {
    if (!file_exists("videos.json")) {
        return ["irl" => [], "anime" => []];
    }
    $contenido = file_get_contents("videos.json");
    $data = json_decode($contenido, true);
    return is_array($data) ? $data : ["irl" => [], "anime" => []];
}

// Guardar videos en el JSON
function guardarVideosJson($data) {
    file_put_contents("videos.json", json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Extraer File ID compatible con estructuras string u objeto
function obtenerFileId($item) {
    return is_array($item) ? ($item["file_id"] ?? "") : $item;
}

// Extraer Nombre/Caption opcional
function obtenerNombre($item) {
    if (is_array($item) && !empty($item["name"])) {
        return $item["name"];
    }
    return "Sin título";
}

// Extraer fecha de subida
function obtenerFecha($item) {
    if (is_array($item) && !empty($item["date"])) {
        return $item["date"];
    }
    return 0;
}

// -------------------------------------------------------------
// SISTEMA DE ESTADOS TEMPORALES PARA EL FLUJO DE SUBIDA (ADMIN)
// -------------------------------------------------------------
function obtenerRutaEstado($chat_id) {
    $dir = "sessions";
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    return $dir . "/state_" . $chat_id . ".json";
}

function cargarEstado($chat_id) {
    $archivo = obtenerRutaEstado($chat_id);
    if (!file_exists($archivo)) return null;
    return json_decode(file_get_contents($archivo), true);
}

function guardarEstado($chat_id, $estado) {
    $archivo = obtenerRutaEstado($chat_id);
    if ($estado === null) {
        if (file_exists($archivo)) unlink($archivo);
    } else {
        file_put_contents($archivo, json_encode($estado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

// -------------------------------------------------------------
// SISTEMA DE HISTORIAL DE VIDEOS VISTOS POR USUARIO
// -------------------------------------------------------------
function obtenerRutaSesion($chat_id) {
    $dir = "sessions";
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    return $dir . "/user_" . $chat_id . ".json";
}

function cargarHistorialUsuario($chat_id) {
    $archivo = obtenerRutaSesion($chat_id);
    if (!file_exists($archivo)) return [];
    $data = json_decode(file_get_contents($archivo), true);
    return is_array($data) ? $data : [];
}

function guardarHistorialUsuario($chat_id, $historial) {
    $archivo = obtenerRutaSesion($chat_id);
    file_put_contents($archivo, json_encode($historial, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Obtiene el siguiente video asegurando cero repeticiones hasta dar la vuelta
function obtenerSiguienteVideoNoVisto($chat_id, $categoria) {
    $videosData = cargarVideos();
    if (!isset($videosData[$categoria]) || empty($videosData[$categoria])) {
        return null;
    }

    $todos_los_videos = $videosData[$categoria];
    $historial = cargarHistorialUsuario($chat_id);
    $vistos_en_categoria = $historial[$categoria] ?? [];
    
    $disponibles = array_filter($todos_los_videos, function($item) use ($vistos_en_categoria) {
        $f_id = obtenerFileId($item);
        return !in_array($f_id, $vistos_en_categoria);
    });

    if (empty($disponibles)) {
        $historial[$categoria] = [];
        $disponibles = $todos_los_videos;
    }

    $ahora = time();
    $tres_dias = 3 * 24 * 60 * 60;
    
    $recientes_no_vistos = array_filter($disponibles, function($item) use ($ahora, $tres_dias) {
        $fecha_video = obtenerFecha($item);
        return ($ahora - $fecha_video) <= $tres_dias;
    });

    $pool_eleccion = !empty($recientes_no_vistos) ? $recientes_no_vistos : $disponibles;

    $item_elegido = $pool_eleccion[array_rand($pool_eleccion)];
    $f_elegido_id = obtenerFileId($item_elegido);

    $historial[$categoria][] = $f_elegido_id;
    guardarHistorialUsuario($chat_id, $historial);

    return $item_elegido;
}

// Genera menú de categorías dinámicamente
function obtenerBotoneraCategorias() {
    $videosData = cargarVideos();
    $inline_keyboard = [];
    $fila = [];

    $inline_keyboard[] = [["text" => "🔥 Novedades (Últimos 3 días)", "callback_data" => "ver_recientes"]];

    foreach ($videosData as $cat => $lista) {
        $cantidad = count($lista);
        $nombreBoton = "📂 " . ucfirst($cat) . " ({$cantidad})";
        $fila[] = ["text" => $nombreBoton, "callback_data" => "cat_" . $cat];
        
        if (count($fila) == 2) {
            $inline_keyboard[] = $fila;
            $fila = [];
        }
    }
    if (!empty($fila)) {
        $inline_keyboard[] = $fila;
    }

    return ["inline_keyboard" => $inline_keyboard];
}

// Botonera de categorías para subir contenido (Admin)
function obtenerBotoneraSubidaCategorias() {
    $videosData = cargarVideos();
    $inline_keyboard = [];
    $fila = [];

    foreach ($videosData as $cat => $lista) {
        $fila[] = ["text" => "📁 " . ucfirst($cat), "callback_data" => "upload_cat_" . $cat];
        if (count($fila) == 2) {
            $inline_keyboard[] = $fila;
            $fila = [];
        }
    }
    if (!empty($fila)) {
        $inline_keyboard[] = $fila;
    }

    $inline_keyboard[] = [["text" => "➕ Crear nueva categoría", "callback_data" => "create_new_cat"]];
    $inline_keyboard[] = [["text" => "❌ Cancelar", "callback_data" => "cancel_upload"]];

    return ["inline_keyboard" => $inline_keyboard];
}

// Generar listado de series Hentai disponibles
function obtenerBotoneraHentaiList() {
    $videosData = cargarVideos();
    $hentaiData = $videosData["hentai"] ?? [];
    if (empty($hentaiData)) {
        return null;
    }

    $inline_keyboard = [];
    foreach ($hentaiData as $key => $serie) {
        $titulo = $serie["titulo"] ?? "Sin título";
        $num_caps = count($serie["capitulos"] ?? []);
        $inline_keyboard[] = [["text" => "🔞 {$titulo} ({$num_caps} Caps)", "callback_data" => "hentai_serie_" . $key]];
    }
    $inline_keyboard[] = [["text" => "📁 Menú de categorías", "callback_data" => "menu_categorias"]];

    return ["inline_keyboard" => $inline_keyboard];
}

// Generar botones de capítulos para una serie Hentai seleccionada
function obtenerBotoneraCapitulosHentai($key) {
    $videosData = cargarVideos();
    $serie = $videosData["hentai"][$key] ?? null;
    if (!$serie || empty($serie["capitulos"])) {
        return null;
    }

    $inline_keyboard = [];
    $fila = [];
    foreach ($serie["capitulos"] as $index => $cap) {
        $num_cap = $index + 1;
        $fila[] = ["text" => "Cap. " . $num_cap, "callback_data" => "hentai_ver_cap_{$key}_{$index}"];
        
        if (count($fila) == 4) { // 4 capítulos por fila para que se vea ordenado
            $inline_keyboard[] = $fila;
            $fila = [];
        }
    }
    if (!empty($fila)) {
        $inline_keyboard[] = $fila;
    }

    $inline_keyboard[] = [["text" => "🔙 Volver al catálogo Hentai", "callback_data" => "cat_hentai"]];
    return ["inline_keyboard" => $inline_keyboard];
}

// Envía un video normal o despliega la serie hentai
function enviarVideoConBotones($chat_id, $categoria) {
    $videosData = cargarVideos();

    if (!isset($videosData[$categoria]) || empty($videosData[$categoria])) {
        telegramApi("sendMessage", [
            "chat_id" => $chat_id,
            "text" => "⚠️ La categoría *{$categoria}* no contiene contenido actualmente.",
            "parse_mode" => "Markdown",
            "reply_markup" => obtenerBotoneraCategorias()
        ]);
        return;
    }

    // Si es la categoría hentai, mostramos el listado de series
    if (strtolower($categoria) === "hentai") {
        $teclado = obtenerBotoneraHentaiList();
        if (!$teclado) {
            telegramApi("sendMessage", [
                "chat_id" => $chat_id,
                "text" => "⚠️ No hay series Hentai disponibles en este momento.",
                "reply_markup" => obtenerBotoneraCategorias()
            ]);
            return;
        }

        telegramApi("sendMessage", [
            "chat_id" => $chat_id,
            "text" => "🔞 *Catálogo de Series Hentai:*\n\nSelecciona una serie para ver su ficha y capítulos:",
            "parse_mode" => "Markdown",
            "reply_markup" => $teclado
        ]);
        return;
    }

    // Comportamiento normal para las demás categorías
    $item = obtenerSiguienteVideoNoVisto($chat_id, $categoria);
    if (!$item) return;

    $file_id = obtenerFileId($item);
    $nombre = obtenerNombre($item);

    $caption = "🎬 *Categoría:* " . ucfirst($categoria);
    if ($nombre !== "Sin título") {
        $caption .= "\n📌 *Título:* " . $nombre;
    }

    $keyboard = [
        "inline_keyboard" => [
            [
                ["text" => "🔄 Siguiente video", "callback_data" => "cat_" . $categoria],
                ["text" => "📁 Menú de categorías", "callback_data" => "menu_categorias"]
            ]
        ]
    ];

    telegramApi("sendVideo", [
        "chat_id" => $chat_id,
        "video" => $file_id,
        "caption" => $caption,
        "parse_mode" => "Markdown",
        "reply_markup" => $keyboard
    ]);
}

// Enviar sección de novedades de los últimos 3 días
function enviarVideoReciente($chat_id) {
    $videosData = cargarVideos();
    $ahora = time();
    $tres_dias = 3 * 24 * 60 * 60;
    $recientes = [];

    foreach ($videosData as $cat => $lista) {
        if ($cat === "hentai") {
            foreach ($lista as $serie) {
                if (isset($serie["date"]) && ($ahora - $serie["date"]) <= $tres_dias) {
                    $recientes[] = ["tipo" => "hentai", "serie" => $serie];
                }
            }
        } else {
            foreach ($lista as $item) {
                $fecha_video = obtenerFecha($item);
                if (($ahora - $fecha_video) <= $tres_dias) {
                    $recientes[] = ["tipo" => "normal", "cat" => $cat, "item" => $item];
                }
            }
        }
    }

    if (empty($recientes)) {
        telegramApi("sendMessage", [
            "chat_id" => $chat_id,
            "text" => "📭 *No hay videos nuevos aun* en los últimos 3 días.",
            "parse_mode" => "Markdown",
            "reply_markup" => obtenerBotoneraCategorias()
        ]);
        return;
    }

    $elegido = $recientes[array_rand($recientes)];

    if ($elegido["tipo"] === "hentai") {
        $serie = $elegido["serie"];
        $titulo = $serie["titulo"] ?? "Sin título";
        $num_caps = count($serie["capitulos"] ?? []);

        telegramApi("sendPhoto", [
            "chat_id" => $chat_id,
            "photo" => $serie["photo_id"],
            "caption" => "🔥 *Novedad Hentai (Últimos 3 días)*\n\n📋 *{$titulo}*\n📦 *Capítulos disponibles:* {$num_caps}\n\n" . $serie["ficha"],
            "parse_mode" => "Markdown"
        ]);
        return;
    }

    $cat = $elegido["cat"];
    $item = $elegido["item"];
    $file_id = obtenerFileId($item);
    $nombre = obtenerNombre($item);

    $caption = "🔥 *Novedad (Últimos 3 días)*\n📂 *Categoría:* " . ucfirst($cat);
    if ($nombre !== "Sin título") {
        $caption .= "\n📌 *Título:* " . $nombre;
    }

    $keyboard = [
        "inline_keyboard" => [
            [
                ["text" => "🔄 Otra novedad", "callback_data" => "ver_recientes"],
                ["text" => "📁 Menú de categorías", "callback_data" => "menu_categorias"]
            ]
        ]
    ];

    telegramApi("sendVideo", [
        "chat_id" => $chat_id,
        "video" => $file_id,
        "caption" => $caption,
        "parse_mode" => "Markdown",
        "reply_markup" => $keyboard
    ]);
}

// -------------------------------------------------------------
// PROCESAR CALLBACKS (CLICS EN LOS BOTONES)
// -------------------------------------------------------------
if (isset($update["callback_query"])) {
    $callback = $update["callback_query"];
    $callback_id = $callback["id"];
    $chat_id = $callback["message"]["chat"]["id"];
    $user_id = $callback["from"]["id"];
    $data = $callback["data"];

    telegramApi("answerCallbackQuery", ["callback_query_id" => $callback_id]);

    $es_admin = in_array($user_id, $admins_permitidos);

    if (strpos($data, "cat_") === 0) {
        $categoria = str_replace("cat_", "", $data);
        enviarVideoConBotones($chat_id, $categoria);
    } 
    elseif (strpos($data, "hentai_serie_") === 0) {
        $key = str_replace("hentai_serie_", "", $data);
        $videosData = cargarVideos();
        $serie = $videosData["hentai"][$key] ?? null;

        if ($serie) {
            $titulo = $serie["titulo"] ?? "Sin título";
            $num_caps = count($serie["capitulos"] ?? []);

            telegramApi("sendPhoto", [
                "chat_id" => $chat_id,
                "photo" => $serie["photo_id"],
                "caption" => "📋 *FICHA TÉCNICA*\n\n📌 *Título:* {$titulo}\n📦 *Contiene:* {$num_caps} capítulos\n\n" . $serie["ficha"],
                "parse_mode" => "Markdown",
                "reply_markup" => obtenerBotoneraCapitulosHentai($key)
            ]);
        }
    }
    elseif (strpos($data, "hentai_ver_cap_") === 0) {
        $partes = explode("_", str_replace("hentai_ver_cap_", "", $data));
        $key = $partes[0];
        $index = intval($partes[1]);

        $videosData = cargarVideos();
        $serie = $videosData["hentai"][$key] ?? null;

        if ($serie && isset($serie["capitulos"][$index])) {
            $file_id = $serie["capitulos"][$index];
            $num_cap = $index + 1;
            $titulo = $serie["titulo"] ?? "Serie Hentai";

            $keyboard = [
                "inline_keyboard" => [
                    [
                        ["text" => "🔙 Volver al Ficha", "callback_data" => "hentai_serie_" . $key],
                        ["text" => "📁 Menú principal", "callback_data" => "menu_categorias"]
                    ]
                ]
            ];

            telegramApi("sendVideo", [
                "chat_id" => $chat_id,
                "video" => $file_id,
                "caption" => "🔞 *{$titulo}* - Capítulo {$num_cap}",
                "parse_mode" => "Markdown",
                "reply_markup" => $keyboard
            ]);
        }
    }
    elseif ($data === "ver_recientes") {
        enviarVideoReciente($chat_id);
    }
    elseif ($data === "menu_categorias") {
        telegramApi("sendMessage", [
            "chat_id" => $chat_id,
            "text" => "✨ *Selecciona una categoría disponible:*",
            "parse_mode" => "Markdown",
            "reply_markup" => obtenerBotoneraCategorias()
        ]);
    }
    elseif ($data === "admin_upload" && $es_admin) {
        telegramApi("sendMessage", [
            "chat_id" => $chat_id,
            "text" => "📤 *Modo Subida Activado*\n\nPrimero, selecciona la categoría a la que deseas subir contenido:",
            "parse_mode" => "Markdown",
            "reply_markup" => obtenerBotoneraSubidaCategorias()
        ]);
    }
    elseif ($data === "cancel_upload" && $es_admin) {
        guardarEstado($chat_id, null);
        telegramApi("sendMessage", [
            "chat_id" => $chat_id,
            "text" => "❌ *Operación finalizada o cancelada con éxito.*",
            "parse_mode" => "Markdown"
        ]);
    }
    elseif ($data === "create_new_cat" && $es_admin) {
        guardarEstado($chat_id, ["step" => "waiting_for_new_category_name"]);

        telegramApi("sendMessage", [
            "chat_id" => $chat_id,
            "text" => "⌨️ Escribe el nombre para la **nueva categoría**:",
            "parse_mode" => "Markdown"
        ]);
    }
    elseif (strpos($data, "upload_cat_") === 0 && $es_admin) {
        $categoria_seleccionada = strtolower(str_replace("upload_cat_", "", $data));

        if ($categoria_seleccionada === "hentai") {
            $videosData = cargarVideos();
            $hentaiData = $videosData["hentai"] ?? [];

            if (!empty($hentaiData)) {
                $inline_keyboard = [];
                foreach ($hentaiData as $key => $serie) {
                    $inline_keyboard[] = [["text" => "📂 " . $serie["titulo"], "callback_data" => "hentai_add_exist_" . $key]];
                }
                $inline_keyboard[] = [["text" => "➕ Crear nueva serie Hentai", "callback_data" => "hentai_create_new"]];
                $inline_keyboard[] = [["text" => "❌ Cancelar", "callback_data" => "cancel_upload"]];

                telegramApi("sendMessage", [
                    "chat_id" => $chat_id,
                    "text" => "🔞 *Categoría Hentai seleccionada*\n\n¿Deseas agregar capítulos a una serie existente o crear una nueva?",
                    "parse_mode" => "Markdown",
                    "reply_markup" => ["inline_keyboard" => $inline_keyboard]
                ]);
                guardarEstado($chat_id, ["category" => "hentai"]);
                exit;
            } else {
                guardarEstado($chat_id, [
                    "category" => "hentai",
                    "step" => "waiting_for_hentai_title"
                ]);

                telegramApi("sendMessage", [
                    "chat_id" => $chat_id,
                    "text" => "🔞 Categoría seleccionada: *Hentai*.\n\nEscribe primero el **nombre o título** de la serie Hentai:",
                    "parse_mode" => "Markdown"
                ]);
                exit;
            }
        } else {
            guardarEstado($chat_id, [
                "category" => $categoria_seleccionada,
                "step" => "waiting_for_normal_video"
            ]);

            telegramApi("sendMessage", [
                "chat_id" => $chat_id,
                "text" => "📂 Categoría seleccionada: *{$categoria_seleccionada}*.\n\nAhora envía o reenvía el video para registrarlo:",
                "parse_mode" => "Markdown",
                "reply_markup" => ["inline_keyboard" => [[["text" => "❌ Cancelar", "callback_data" => "cancel_upload"]]]]
            ]);
        }
    }
    elseif ($data === "hentai_create_new" && $es_admin) {
        guardarEstado($chat_id, [
            "category" => "hentai",
            "step" => "waiting_for_hentai_title"
        ]);

        telegramApi("sendMessage", [
            "chat_id" => $chat_id,
            "text" => "⌨️ Escribe el **nombre o título** de la nueva serie Hentai:",
            "parse_mode" => "Markdown"
        ]);
    }
    elseif (strpos($data, "hentai_add_exist_") === 0 && $es_admin) {
        $key = str_replace("hentai_add_exist_", "", $data);
        guardarEstado($chat_id, [
            "category" => "hentai",
            "hentai_key" => $key,
            "step" => "waiting_for_hentai_chapters"
        ]);

        $videosData = cargarVideos();
        $titulo = $videosData["hentai"][$key]["titulo"] ?? "Serie";

        telegramApi("sendMessage", [
            "chat_id" => $chat_id,
            "text" => "🔞 Serie seleccionada: *{$titulo}*.\n\nEnvía o reenvía los capítulos uno a uno. Cuando termines presiona Finalizar:",
            "parse_mode" => "Markdown",
            "reply_markup" => ["inline_keyboard" => [[["text" => "✅ Finalizar serie", "callback_data" => "cancel_upload"]]]]
        ]);
    }
    elseif ($data === "name_no" && $es_admin) {
        $estado = cargarEstado($chat_id);
        if ($estado && isset($estado["file_id"], $estado["category"])) {
            $file_id = $estado["file_id"];
            $categoria = $estado["category"];

            $videos = cargarVideos();
            if (!isset($videos[$categoria])) $videos[$categoria] = [];
            
            $videos[$categoria][] = [
                "file_id" => $file_id,
                "date" => time()
            ];
            guardarVideosJson($videos);
            guardarEstado($chat_id, null);

            telegramApi("sendMessage", [
                "chat_id" => $chat_id,
                "text" => "✅ *¡Video subido exitosamente!*\n\n📂 *Categoría:* " . ucfirst($categoria),
                "parse_mode" => "Markdown",
                "reply_markup" => [
                    "inline_keyboard" => [
                        [
                            ["text" => "📤 Subir otro contenido", "callback_data" => "admin_upload"],
                            ["text" => "📁 Menú principal", "callback_data" => "menu_categorias"]
                        ]
                    ]
                ]
            ]);
        }
    }
    elseif ($data === "name_yes" && $es_admin) {
        $estado = cargarEstado($chat_id);
        if ($estado) {
            $estado["step"] = "waiting_for_name_text";
            guardarEstado($chat_id, $estado);

            telegramApi("sendMessage", [
                "chat_id" => $chat_id,
                "text" => "✍️ Envía el título o nombre del video:",
                "parse_mode" => "Markdown"
            ]);
        }
    }
    exit;
}

// -------------------------------------------------------------
// PROCESAR MENSAJES DE TEXTO Y MEDIOS (VIDEOS/FOTOS)
// -------------------------------------------------------------
if (isset($update["message"])) {
    $message = $update["message"];
    $chat_id = $message["chat"]["id"];
    $user_id = $message["from"]["id"] ?? 0;
    $username = $message["from"]["username"] ?? $message["from"]["first_name"] ?? "Amigo";
    $text = trim($message["text"] ?? "");

    $es_admin = in_array($user_id, $admins_permitidos);
    $estado_actual = cargarEstado($chat_id);

    // 1. Crear nueva categoría general
    if ($es_admin && $estado_actual && ($estado_actual["step"] ?? "") === "waiting_for_new_category_name" && !empty($text)) {
        $nueva_cat = strtolower(trim($text));
        $nueva_cat = preg_replace('/[^a-z0-9_-]/', '', $nueva_cat);

        if (!empty($nueva_cat)) {
            $videos = cargarVideos();
            if (!isset($videos[$nueva_cat])) {
                $videos[$nueva_cat] = [];
                guardarVideosJson($videos);
            }

            if ($nueva_cat === "hentai") {
                guardarEstado($chat_id, [
                    "category" => "hentai",
                    "step" => "waiting_for_hentai_title"
                ]);
                telegramApi("sendMessage", [
                    "chat_id" => $chat_id,
                    "text" => "🎉 Categoría *Hentai* creada.\n\nEscribe el **nombre o título** de la serie Hentai:",
                    "parse_mode" => "Markdown"
                ]);
            } else {
                guardarEstado($chat_id, [
                    "category" => $nueva_cat,
                    "step" => "waiting_for_normal_video"
                ]);
                telegramApi("sendMessage", [
                    "chat_id" => $chat_id,
                    "text" => "🎉 Categoría *{$nueva_cat}* creada.\n\nAhora envía o reenvía el video para registrarlo:",
                    "parse_mode" => "Markdown"
                ]);
            }
            exit;
        }
    }

    // 2. Recibir Título para la nueva Serie Hentai
    if ($es_admin && $estado_actual && ($estado_actual["step"] ?? "") === "waiting_for_hentai_title" && !empty($text)) {
        $estado_actual["hentai_titulo"] = $text;
        $estado_actual["step"] = "waiting_for_hentai_photo";
        guardarEstado($chat_id, $estado_actual);

        telegramApi("sendMessage", [
            "chat_id" => $chat_id,
            "text" => "📌 Título guardado: *{$text}*.\n\nAhora envía la **imagen (foto)** de portada para la ficha técnica:",
            "parse_mode" => "Markdown"
        ]);
        exit;
    }

    // 3. Recibir Foto de portada Hentai
    if ($es_admin && $estado_actual && ($estado_actual["step"] ?? "") === "waiting_for_hentai_photo" && isset($message["photo"])) {
        $photos = $message["photo"];
        $bestPhoto = end($photos);
        $photo_id = $bestPhoto["file_id"];

        $estado_actual["photo_id"] = $photo_id;
        $estado_actual["step"] = "waiting_for_hentai_ficha_text";
        guardarEstado($chat_id, $estado_actual);

        telegramApi("sendMessage", [
            "chat_id" => $chat_id,
            "text" => "📝 Imagen recibida.\n\nAhora envía el **texto de la ficha técnica** (sinopsis, detalles, etc.):",
            "parse_mode" => "Markdown"
        ]);
        exit;
    }

    // 4. Recibir Ficha Técnica Hentai y crear la serie en el JSON
    if ($es_admin && $estado_actual && ($estado_actual["step"] ?? "") === "waiting_for_hentai_ficha_text" && !empty($text)) {
        $ficha_texto = $text;
        $titulo = $estado_actual["hentai_titulo"];
        $photo_id = $estado_actual["photo_id"];

        $videos = cargarVideos();
        if (!isset($videos["hentai"])) $videos["hentai"] = [];

        // Guardar la nueva serie con capítulos vacíos inicialmente
        $videos["hentai"][] = [
            "titulo" => $titulo,
            "photo_id" => $photo_id,
            "ficha" => $ficha_texto,
            "capitulos" => [],
            "date" => time()
        ];
        $new_index = count($videos["hentai"]) - 1;
        guardarVideosJson($videos);

        // Actualizar estado para empezar a recibir los capítulos de esta serie
        $estado_actual["hentai_key"] = $new_index;
        $estado_actual["step"] = "waiting_for_hentai_chapters";
        guardarEstado($chat_id, $estado_actual);

        telegramApi("sendMessage", [
            "chat_id" => $chat_id,
            "text" => "📋 *Ficha técnica guardada y serie creada.*\n\n¡Perfecto! Ahora envía o reenvía los capítulos uno por uno. Cuando termines, presiona el botón 'Finalizar serie'.",
            "parse_mode" => "Markdown",
            "reply_markup" => ["inline_keyboard" => [[["text" => "✅ Finalizar serie", "callback_data" => "cancel_upload"]]]]
        ]);
        exit;
    }

    // 5. Recepción progresiva de capítulos Hentai
    if ($es_admin && $estado_actual && ($estado_actual["step"] ?? "") === "waiting_for_hentai_chapters") {
        $archivo = $message["video"] ?? $message["document"] ?? $message["animation"] ?? null;
        if ($archivo && isset($archivo["file_id"])) {
            $file_id = $archivo["file_id"];
            $key = $estado_actual["hentai_key"] ?? null;

            if ($key !== null) {
                $videosData = cargarVideos();
                if (isset($videosData["hentai"][$key])) {
                    $videosData["hentai"][$key]["capitulos"][] = $file_id;
                    guardarVideosJson($videosData);
                    $num_cap = count($videosData["hentai"][$key]["capitulos"]);

                    telegramApi("sendMessage", [
                        "chat_id" => $chat_id,
                        "text" => "✅ ¡Capítulo {$num_cap} agregado con éxito!",
                        "reply_markup" => ["inline_keyboard" => [[["text" => "✅ Finalizar serie", "callback_data" => "cancel_upload"]]]]
                    ]);
                }
            }
            exit;
        }
    }

    // 6. Recepción de video para categorías normales (Anime, IRL, etc.)
    if ($es_admin && $estado_actual && ($estado_actual["step"] ?? "") === "waiting_for_normal_video") {
        $archivo = $message["video"] ?? $message["document"] ?? $message["animation"] ?? null;
        if ($archivo && isset($archivo["file_id"])) {
            $file_id = $archivo["file_id"];
            $estado_actual["file_id"] = $file_id;
            $estado_actual["step"] = "choosing_name";
            guardarEstado($chat_id, $estado_actual);

            telegramApi("sendMessage", [
                "chat_id" => $chat_id,
                "text" => "📥 *Video recibido con éxito.*\n\n¿Quieres ponerle un nombre al video?",
                "parse_mode" => "Markdown",
                "reply_markup" => [
                    "inline_keyboard" => [
                        [
                            ["text" => "✍️ Dar nombre", "callback_data" => "name_yes"],
                            ["text" => "⏭️ Sin nombre", "callback_data" => "name_no"]
                        ],
                        [
                            ["text" => "❌ Cancelar", "callback_data" => "cancel_upload"]
                        ]
                    ]
                ]
            ]);
            exit;
        }
    }

    // 7. Nombre normal de video
    if ($es_admin && $estado_actual && ($estado_actual["step"] ?? "") === "waiting_for_name_text" && !empty($text)) {
        $nombre_video = $text;
        $file_id = $estado_actual["file_id"];
        $categoria = $estado_actual["category"];

        $videos = cargarVideos();
        if (!isset($videos[$categoria])) $videos[$categoria] = [];
        
        $videos[$categoria][] = [
            "file_id" => $file_id,
            "name" => $nombre_video,
            "date" => time()
        ];
        guardarVideosJson($videos);
        guardarEstado($chat_id, null);

        telegramApi("sendMessage", [
            "chat_id" => $chat_id,
            "text" => "🔥 *¡Video registrado con éxito!*\n\n📂 *Categoría:* " . ucfirst($categoria) . "\n📌 *Nombre:* " . $nombre_video,
            "parse_mode" => "Markdown",
            "reply_markup" => [
                "inline_keyboard" => [
                    [
                        ["text" => "📤 Subir otro contenido", "callback_data" => "admin_upload"],
                        ["text" => "📁 Menú principal", "callback_data" => "menu_categorias"]
                    ]
                ]
            ]
        ]);
        exit;
    }

    // 8. Comando /start
    if (strpos($text, "/start") === 0) {
        if ($es_admin) {
            $textoBienvenida = "👑 *¡Bienvenido mi amo administrador @{$username}!*\n\n¿Qué desea hacer hoy?";
            $keyboard = [
                "inline_keyboard" => [
                    [
                        ["text" => "📤 Subir nuevo contenido", "callback_data" => "admin_upload"],
                        ["text" => "🎬 Ver videos (Modo usuario)", "callback_data" => "menu_categorias"]
                    ]
                ]
            ];
        } else {
            $textoBienvenida = "🤖 *¡Bienvenido mi amo @{$username}!* ¿Qué desea ver?\n\nElige una categoría o revisa las novedades:";
            $keyboard = obtenerBotoneraCategorias();
        }

        telegramApi("sendMessage", [
            "chat_id" => $chat_id,
            "text" => $textoBienvenida,
            "parse_mode" => "Markdown",
            "reply_markup" => $keyboard
        ]);
    }
    elseif (strpos($text, "/categorias") === 0 || strpos($text, "/menu") === 0) {
        telegramApi("sendMessage", [
            "chat_id" => $chat_id,
            "text" => "📂 *Categorías disponibles en el sistema:*",
            "parse_mode" => "Markdown",
            "reply_markup" => obtenerBotoneraCategorias()
        ]);
    }
    elseif (strpos($text, "/ver") === 0) {
        $parts = explode(" ", $text);
        
        if (count($parts) < 2) {
            telegramApi("sendMessage", [
                "chat_id" => $chat_id,
                "text" => "⚠️ *Categoría no especificada o inválida.*\n\nConsulta la lista de categorías disponibles:",
                "parse_mode" => "Markdown",
                "reply_markup" => obtenerBotoneraCategorias()
            ]);
            exit;
        }

        $categoria = strtolower($parts[1]);
        $videosData = cargarVideos();

        if (!isset($videosData[$categoria])) {
            telegramApi("sendMessage", [
                "chat_id" => $chat_id,
                "text" => "❌ La categoría *'{$categoria}'* no existe.\n\nElije una categoría de la lista:",
                "parse_mode" => "Markdown",
                "reply_markup" => obtenerBotoneraCategorias()
            ]);
            exit;
        }

        enviarVideoConBotones($chat_id, $categoria);
    }

    if (!empty($text) && strpos($text, "/") === 0) {
        telegramApi("sendMessage", [
            "chat_id" => $chat_id,
            "text" => "❓ *Comando no reconocido.*\n\nPuedes explorar el catálogo usando los botones:",
            "parse_mode" => "Markdown",
            "reply_markup" => obtenerBotoneraCategorias()
        ]);
    }
}
?>