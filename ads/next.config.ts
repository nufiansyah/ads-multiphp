import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  env: {
    NEXT_PUBLIC_BACKEND_URL: 'http://localhost:5000/multi.php?'
  }
};

export default nextConfig;
