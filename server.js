// -------------------------------------------
// CityTour Amsterdam – TTS Server (Streaming)
// -------------------------------------------
import express from "express";
import cors from "cors";
import dotenv from "dotenv";
import fetch from "node-fetch";
import path from "path";
import { fileURLToPath } from "url";

dotenv.config();
const app = express();
app.use(cors());
app.use(express.json());

const __dirname = path.dirname(fileURLToPath(import.meta.url));

// -------------------------------------------
// API KEYS
// -------------------------------------------
const API_KEY = process.env.ELEVENLABS_API_KEY;       // Belangrijk: deze MOET in Render staan!
const VOICE_ID = process.env.ELEVENLABS_VOICE_ID || "EXAVITQu4vr4xnSDxMaL";

// -------------------------------------------
// Root testpagina
// -------------------------------------------
app.get("/", (req, res) => {
  res.sendFile(path.join(__dirname, "11labs.html"));
});

// -------------------------------------------
// /speak → STREAMING AUDIO (geen base64, geen mp3)
// -------------------------------------------
app.get("/speak", async (req, res) => {
  let text = req.query.text || "";
  console.log("TTS ontvangen (RAW):", text);

  // HTML verwijderen voor veiligheid
  text = text
    .replace(/<[^>]*>?/gm, "")
    .replace(/&nbsp;/g, " ")
    .trim();

  console.log("TTS schoon:", text);

  try {
    const response = await fetch(
      `https://api.elevenlabs.io/v1/text-to-speech/${VOICE_ID}/stream`,
      {
        method: "POST",
        headers: {
          "xi-api-key": API_KEY,
          "Content-Type": "application/json",
          "Accept": "audio/mpeg"
        },
        body: JSON.stringify({
          text,
          model_id: "eleven_multilingual_v2",
        }),
      }
    );

    if (!response.ok) {
      console.log("ElevenLabs fout bij streaming");
      return res.status(500).send("TTS fout");
    }

    // Zet streaming audio direct door naar de browser
    res.setHeader("Content-Type", "audio/mpeg");
    response.body.pipe(res);

  } catch (err) {
    console.log("Server fout:", err);
    res.status(500).send("Interne serverfout");
  }
});

// -------------------------------------------
// Server starten
// -------------------------------------------
// -------------------------------------------
// /speak → POST testendpoint
// -------------------------------------------
app.post("/speak", (req, res) => {
  console.log("POST /speak ontvangen:", req.body);
  res.send("POST /speak werkt!");
});


const PORT = process.env.PORT || 3000;
app.listen(PORT, () => {
  console.log("TTS server (streaming) draait op poort", PORT);
});

