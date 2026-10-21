import json
import random
from telegram import Update
from telegram.ext import ApplicationBuilder, CommandHandler, MessageHandler, filters, ContextTypes

# ==========================================
# PEGA TU TOKEN DE BOTFATHER AQUÍ:
TOKEN = "8712018627:AAEDXu99ahgQeu_koBMSMAOheD7MtGPJ9g4"
# ==========================================

# Historial para recordar los últimos videos enviados y evitar repeticiones rápidas
historial_recientes = []

# Función para leer el archivo JSON
def leer_datos():
    try:
        with open("videos.json", "r", encoding="utf-8") as archivo:
            return json.load(archivo)
    except FileNotFoundError:
        return {}

# Mensaje de bienvenida (/start)
async def start(update: Update, context: ContextTypes.DEFAULT_TYPE):
    datos = leer_datos()
    categorias = ", ".join(datos.keys()) if datos else "Ninguna"
    
    await update.message.reply_text(
        f"¡Hola! Bot encendido y listo.\n\n"
        f"📌 **Para conseguir el ID de un video:** Solo envíamelo aquí.\n"
        f"📂 **Para ver videos:** Usa `/ver [categoria]`\n\n"
        f"Categorías disponibles: {categorias}"
    )

# Función para extraer el file_id de los videos enviados al chat
async def obtener_id(update: Update, context: ContextTypes.DEFAULT_TYPE):
    if update.message.video:
        file_id_real = update.message.video.file_id
        await update.message.reply_text(
            f"📋 **¡Video detectado con éxito!**\n\n"
            f"Copia este código para tu `videos.json`:\n\n`{file_id_real}`",
            parse_mode="Markdown"
        )
    else:
        await update.message.reply_text("⚠️ Por favor, envíame un archivo de video (no texto ni fotos) para extraer su ID.")

# Comando para enviar el video evitando repeticiones recientes
async def enviar_video(update: Update, context: ContextTypes.DEFAULT_TYPE):
    global historial_recientes
    
    if not context.args:
        await update.message.reply_text("⚠️ Escribe una categoría. Ejemplo: `/ver anime`")
        return

    categoria_buscada = context.args[0].lower()
    datos = leer_datos()

    if categoria_buscada in datos:
        lista_videos = datos[categoria_buscada]
        
        if not lista_videos:
            await update.message.reply_text("❌ Esta categoría todavía no tiene videos.")
            return
            
        # Si la categoría tiene 4 o menos videos, ajustamos el límite para que no se bloquee
        limite_historial = min(3, len(lista_videos) - 1) if len(lista_videos) > 1 else 0

        # Filtramos los videos que NO están en los últimos enviados
        videos_disponibles = [v for v in lista_videos if v not in historial_recientes]

        # Si por alguna razón todos están en el historial, reiniciamos la memoria
        if not videos_disponibles:
            videos_disponibles = lista_videos

        # Elegimos uno al azar de los disponibles
        video_elegido = random.choice(videos_disponibles)

        # Añadimos el video elegido al historial
        historial_recientes.append(video_elegido)

        # Si el historial supera los 3 elementos, borramos el más antiguo para mantener la regla
        if len(historial_recientes) > limite_historial:
            historial_recientes.pop(0)

        # Intentamos enviar el video
        try:
            await update.message.reply_video(video=video_elegido)
        except Exception as e:
            await update.message.reply_text(f"❌ Error al enviar el video. ¿El file_id es correcto? (Detalle: {e})")
    else:
        await update.message.reply_text("❌ Categoría no encontrada. Revisa las disponibles con /start")

def main():
    app = ApplicationBuilder().token(TOKEN).build()

    # Registramos los comandos y el detector de videos
    app.add_handler(CommandHandler("start", start))
    app.add_handler(CommandHandler("ver", enviar_video))
    app.add_handler(MessageHandler(filters.VIDEO, obtener_id))

    print("🤖 Bot listo con filtro anti-repetición...")
    app.run_polling()

if __name__ == "__main__":
    main()
