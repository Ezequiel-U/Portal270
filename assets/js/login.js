const roleCards = document.querySelectorAll(".role-card");
const passwordInput = document.querySelector("#password");
const passwordToggle = document.querySelector(".password-toggle");

roleCards.forEach((card) => {
    card.addEventListener("click", () => {
        roleCards.forEach((item) => {
            item.classList.remove("active");
        });

        card.classList.add("active");
    });
});

passwordToggle.addEventListener("click", () => {
    const isPassword = passwordInput.type === "password";

    passwordInput.type = isPassword ? "text" : "password";
    passwordToggle.textContent = isPassword ? "🙈" : "👁";
    passwordToggle.setAttribute(
        "aria-label",
        isPassword ? "Ocultar contraseña" : "Mostrar contraseña"
    );
});