/**
 * The mascot of the access screens (login, pre-login, sign-up): ONE component so every header uses the same image. It replaces the hexagon that used to sit above the brand name.
 * The file is `public/brand/mascota-transparente.png`: the blue character extracted from the delivered `Mascota2.jpg` (kept untouched) with a real alpha channel, so it floats on the
 * screen's own background with no tile, frame or shadow. Shown whole (no crop, no stretch); decorative (the brand name is printed right below), hence the empty alt.
 */
export default function BrandMark() {
    return (
        <div className="logo-mark">
            <img src="/brand/mascota-transparente.png" alt="" width="800" height="733" decoding="async" />
        </div>
    );
}
