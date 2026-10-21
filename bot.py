import json
import random
import os
from telegram import Update
from telegram.ext import Application, CommandHandler, ContextTypes

# Token del bot configurado directamente para evitar fallos en Railway
TOKEN = os.getenv("TELEGRAM_TOKEN", "8712018627:AAEDXu99ahgQeu_koBMSMAOheD7MtGPJ9g4")

# Diccionario para llevar el control del siguiente video por categoría (orden secuencial)
indices_actuales = {}

def cargar_videos():
    """Lee el archivo videos.json para obtener la lista actualizada de la nube."""
    if os.path.exists("videos.json"):
        with open("videos.json", "r", encoding="utf-8") as f:
            return json.load(f)
    # Actualizado "juegos" por "irl" para que coincida con tu nueva categoría
    return {"anime": [], "irl": []}

async def ver_video(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """
    Maneja el comando /ver <categoria> [cantidad]
    - Envía los videos en orden secuencial (del 1 al 10).
    - Permite elegir cuántos enviar (máximo 3 juntos).
    """
    datos = cargar_videos()
    
    # Valores por defecto
    categoria = "anime"
    cantidad = 1

    # Analizar los argumentos que escribe el usuario (ej: /ver anime 2)
    if context.args:
        # El primer argumento puede ser la categoría o un número
        primer_arg = context.args[0].lower()
        if primer_arg.isdigit():
            cantidad = int(primer_arg)
        else:
            categoria = primer_arg
            # Si hay un segundo argumento y es un número, es la cantidad
            if len(context.args) > 1 and context.args[1].isdigit():
                cantidad = int(context.args[1])

    lista_videos = datos.get(categoria, [])
    
    if not lista_videos:
        await update.message.reply_text(f"No hay videos disponibles en la categoría '{categoria}'.")
        return

    # Limitar la cantidad solicitada entre 1 y un máximo de 3
    cantidad = max(1, min(cantidad, 3))

    # Asegurarnos de iniciar el seguimiento de la categoría si no existe
    if categoria not in indices_actuales:
        indices_actuales[categoria] = 0

    videos_a_enviar = []
    total_videos = len(lista_videos)

    # Seleccionar la cantidad de videos en orden secuencial (dando la vuelta al llegar al final)
    for _ in range(cantidad):
        indice_actual = indices_actuales[categoria]
        videos_a_enviar.append(lista_videos[indice_actual])
        
        # Avanzar al siguiente índice de manera circular (vuelve al 0 al llegar al final)
        indices_actuales[categoria] = (indice_actual + 1) % total_videos

    # Enviar los videos juntos (o el video si es solo 1)
    if len(videos_a_enviar) == 1:
        await update.message.reply_video(video=videos_a_enviar[0])
    else:
        # Si son varios, usamos media group para que lleguen juntos en un solo mensaje múltiple
        from telegram import InputMediaVideo
        media_group = [InputMediaVideo(media=v) for v in videos_a_enviar]
        await update.message.reply_media_group(media=media_group)

def main():
    # Inicializar la aplicación del bot
    app = Application.builder().token(TOKEN).build()

    # Registrar el comando /ver
    app.add_handler(CommandHandler("ver", ver_video))

    print("Bot en ejecución con orden secuencial y límite de bloque...")
    
    # Iniciar el bot en modo polling continuo
    app.run_polling()

if __name__ == "__main__":
    main()
