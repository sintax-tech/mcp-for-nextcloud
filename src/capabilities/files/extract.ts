import mammoth from "mammoth";
import { extractText as unpdfExtractText, getDocumentProxy } from "unpdf";

function isPdf(filename: string, ct: string): boolean {
  return ct.includes("pdf") || filename.toLowerCase().endsWith(".pdf");
}
function isDocx(filename: string, ct: string): boolean {
  return ct.includes("wordprocessingml") || filename.toLowerCase().endsWith(".docx");
}

export async function extractText(buffer: Buffer, filename: string, contentType: string): Promise<string> {
  try {
    if (isPdf(filename, contentType)) {
      const pdf = await getDocumentProxy(new Uint8Array(buffer));
      const { text } = await unpdfExtractText(pdf, { mergePages: true });
      return text.trim();
    }
    if (isDocx(filename, contentType)) {
      return (await mammoth.extractRawText({ buffer })).value.trim();
    }
    return buffer.toString("utf8");
  } catch {
    return `[não foi possível extrair o texto de ${filename}]`;
  }
}
