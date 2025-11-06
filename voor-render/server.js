import express from "express";
import fetch from "node-fetch";
import cors from "cors";

const app = express();
app.use(cors());

const PORT = process.env.PORT || 10000;
const API_KEY = process.env.API_KEY;

app.get("/tts", async (req, res) => {
  const text = req.query.text || "Hallo Bart!";
  console.log("Ontvangen tekst:", text);

  try {
    const response = await fetch(
      "https://api.elevenlabs.io/v1/text-to-speech/pNInz6obpgDQGcFmaJgB/stream",
      {
        method: "POST",
        headers: {
          "xi-api-key": API_KEY,
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          text,
          model_id: "eleven_multilingual_v2",
          voice_settings: { stability: 0.4, similarity_boost: 0.9 },
        }),
      }
    );

    if (!response.ok) {
      const errorText = await response.text();
      console.error("API-fout:", errorText);
      return res
        .status(500)
        .send("Fout bij ophalen audio van ElevenLabs: " + errorText);
    }

    res.set("Content-Type", "audio/mpeg");
    response.body.pipe(res);
  } catch (error) {
    console.error("Serverfout:", error);
    res.status(500).send("Serverfout bij ophalen audio van ElevenLabs.");
  }
});

app.listen(PORT, () => {
  console.log(`✅ Server draait op poort ${PORT}`);
});
