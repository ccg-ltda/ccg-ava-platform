/**
 * The mascot of the access screens (login, pre-login, sign-up): ONE component so every header uses the same image. It replaces the hexagon that used to sit above the brand name.
 * The file is the original `public/brand/Mascota.jpg`, untouched: it is a square image on a dark tile, shown whole (no crop, no stretch) inside rounded corners so the tile reads as an app icon.
 * Decorative (the brand name is printed right below), hence the empty alt.
 */
export default function BrandMark() {
    return (
        <div className="logo-mark">
            <img src="/brand/Mascota.jpg" alt="" width="1049" height="1010" decoding="async" />
        </div>
    );
}
